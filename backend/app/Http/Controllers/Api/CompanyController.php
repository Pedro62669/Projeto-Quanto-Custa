<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Production\QuotePdfGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * O perfil da própria empresa.
 *
 * Estes campos não são decoração: `legal_name`, `document`, endereço e contatos
 * montam o cabeçalho do orçamento comercial em PDF, e o `logo_path` é a marca
 * que chega ao cliente final. Até esta rota existir, toda proposta emitida saía
 * sem CNPJ, sem endereço e sem logotipo — o degradê "sem marca" do
 * QuotePdfGenerator disparava sempre, porque nada preenchia o campo.
 *
 * A empresa vem SEMPRE do usuário autenticado, nunca de um id na requisição.
 * Aceitar `tenant_id` aqui seria entregar o cadastro do concorrente a um PUT.
 */
class CompanyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenant = $this->empresaDo($request);

        return response()->json(['data' => $this->representa($tenant)]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = $this->empresaDo($request);

        $dados = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],

            /*
             * Único entre empresas: dois inquilinos com o mesmo CNPJ significam
             * ou fraude ou cadastro duplicado, e nos dois casos a nota que sair
             * de um vai bater com o outro. `ignore` para a empresa não colidir
             * consigo mesma ao salvar o resto do formulário.
             */
            'document' => [
                'nullable', 'string', 'max:14',
                Rule::unique('tenants', 'document')->ignore($tenant->id),
            ],

            'email' => ['nullable', 'email', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'instagram' => ['nullable', 'string', 'max:100'],
            'tiktok' => ['nullable', 'string', 'max:100'],
            'facebook' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:255'],

            'postal_code' => ['nullable', 'string', 'max:9'],
            'street' => ['nullable', 'string', 'max:255'],
            'street_number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],
        ], [
            'document.unique' => 'Já existe uma empresa cadastrada com este CNPJ.',
            'state.size' => 'Informe a UF com duas letras, ex.: SP.',
        ]);

        /*
         * update() e não forceFill(): os campos acima estão todos no $fillable
         * do Tenant, e os de PLANO não estão — de propósito. É essa fronteira
         * que impede um payload com "plan_type": "pro" nesta rota de virar um
         * upgrade grátis. Trocar por forceFill aqui abriria exatamente isso.
         */
        $tenant->update($dados);

        return response()->json([
            'data' => $this->representa($tenant->fresh()),
            'message' => 'Dados da empresa atualizados.',
        ]);
    }

    /**
     * Envia o logotipo que vai no cabeçalho das propostas.
     *
     * O teto vem de QuotePdfGenerator::MAX_LOGO_BYTES, e não de um número
     * digitado aqui. Dois limites separados divergiriam no primeiro ajuste, e a
     * divergência teria a pior forma possível: o upload aceitaria uma imagem que
     * o gerador de PDF depois recusa em silêncio, e o usuário veria a proposta
     * sair sem marca sem entender por quê.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $tenant = $this->empresaDo($request);

        $maxKb = (int) (QuotePdfGenerator::MAX_LOGO_BYTES / 1024);

        $request->validate([
            /*
             * `mimes` e não só `image`: o dompdf renderiza PNG, JPEG e GIF. SVG
             * passaria numa validação genérica de imagem, não seria desenhado no
             * PDF — e ainda é um formato que carrega script.
             */
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg', "max:{$maxKb}"],
        ], [
            'logo.mimes' => 'Envie PNG ou JPG. Outros formatos não são desenhados no PDF.',
            'logo.max' => 'A imagem passa de '.round($maxKb / 1024, 1).'MB. '
                .'Um logotipo grande demais gera um PDF que o WhatsApp recusa.',
        ]);

        $anterior = $tenant->logo_path;

        $caminho = $request->file('logo')->store("tenants/{$tenant->id}", $this->disco());

        $tenant->update(['logo_path' => $caminho]);

        // Só depois de gravar o novo: se o disco falhar no meio, a empresa fica
        // com o logotipo antigo em vez de nenhum.
        $this->apaga($anterior);

        return response()->json([
            'data' => $this->representa($tenant->fresh()),
            'message' => 'Logotipo atualizado. Ele aparece no cabeçalho das próximas propostas.',
        ]);
    }

    public function destroyLogo(Request $request): JsonResponse
    {
        $tenant = $this->empresaDo($request);

        $this->apaga($tenant->logo_path);
        $tenant->update(['logo_path' => null]);

        return response()->json(['message' => 'Logotipo removido.']);
    }

    /**
     * Transmite o logotipo para a interface.
     *
     * O disco é privado (`local`), e continua sendo de propósito. Publicá-lo
     * daria uma URL adivinhável por id — e a lista de quem assina o SaaS viraria
     * enumerável de fora, sem autenticação nenhuma. O custo é este método; o
     * benefício é que só quem tem sessão na empresa enxerga a marca dela.
     */
    public function showLogo(Request $request): StreamedResponse
    {
        $tenant = $this->empresaDo($request);
        $disco = Storage::disk($this->disco());

        abort_if(
            $tenant->logo_path === null || ! $disco->exists($tenant->logo_path),
            JsonResponse::HTTP_NOT_FOUND,
            'Esta empresa não tem logotipo.',
        );

        return $disco->response($tenant->logo_path);
    }

    /**
     * A empresa do usuário autenticado, com a barreira de papel.
     *
     * Admin de plataforma cai aqui em 422 e não em erro obscuro: ele não tem
     * empresa, e para editar a de um assinante existe /api/platform.
     */
    private function empresaDo(Request $request): Tenant
    {
        $usuario = $request->user();

        abort_if(
            $usuario->tenant === null,
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            'Usuário sem empresa vinculada. Use o painel de plataforma.',
        );

        abort_if(
            ! $usuario->isAdmin() && $request->method() !== 'GET',
            JsonResponse::HTTP_FORBIDDEN,
            'Só o administrador da empresa altera estes dados.',
        );

        return $usuario->tenant;
    }

    private function disco(): string
    {
        // O mesmo que o QuotePdfGenerator lê ao embutir a imagem no PDF.
        return (string) config('filesystems.default');
    }

    private function apaga(?string $caminho): void
    {
        if ($caminho !== null && $caminho !== '') {
            Storage::disk($this->disco())->delete($caminho);
        }
    }

    /**
     * O que a interface consome.
     *
     * `logo_url` aponta para a rota autenticada de streaming, não para o caminho
     * no disco — o caminho não é servível, e devolvê-lo faria a tela mostrar uma
     * imagem quebrada.
     *
     * @return array<string, mixed>
     */
    private function representa(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'legal_name' => $tenant->legal_name,
            'document' => $tenant->document,
            'email' => $tenant->email,
            'whatsapp' => $tenant->whatsapp,
            'phone' => $tenant->phone,
            'instagram' => $tenant->instagram,
            'tiktok' => $tenant->tiktok,
            'facebook' => $tenant->facebook,
            'website' => $tenant->website,
            'postal_code' => $tenant->postal_code,
            'street' => $tenant->street,
            'street_number' => $tenant->street_number,
            'complement' => $tenant->complement,
            'district' => $tenant->district,
            'city' => $tenant->city,
            'state' => $tenant->state,
            'logo_url' => $tenant->logo_path === null ? null : url('/api/company/logo'),

            /*
             * O que ainda falta para a proposta sair completa. A tela usa isto
             * para avisar ANTES da primeira emissão, em vez de deixar o usuário
             * descobrir olhando um PDF sem CNPJ que já foi para o cliente.
             */
            'pendencias_para_pdf' => $this->pendencias($tenant),
        ];
    }

    /** @return list<string> */
    private function pendencias(Tenant $tenant): array
    {
        $faltando = [];

        if ($tenant->legal_name === null || $tenant->legal_name === '') {
            $faltando[] = 'razão social';
        }

        if ($tenant->document === null || $tenant->document === '') {
            $faltando[] = 'CNPJ ou CPF';
        }

        if ($tenant->city === null || $tenant->city === '') {
            $faltando[] = 'cidade';
        }

        if ($tenant->whatsapp === null && $tenant->email === null && $tenant->phone === null) {
            $faltando[] = 'ao menos um contato';
        }

        if ($tenant->logo_path === null) {
            $faltando[] = 'logotipo';
        }

        return $faltando;
    }
}
