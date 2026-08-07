"""
Exporta box-mailer.blend para glTF, para o preview 3D da calculadora consumir.

Rode headless, sem abrir o Blender:

    ~/blender/blender-5.2.0-linux-x64/blender -b mailer/box-mailer.blend \
        --python mailer/export_gltf.py

O que sai daqui é a peça do cliente, com a hierarquia de vincos e a animação
de dobra preservadas — o app não redesenha a caixa, ele CARREGA esta.

Duas coisas ficam de fora de propósito: cenário (chão, luzes, câmera), que a
cena do R3F já tem, e os modificadores ficam APLICADOS, senão a chapa exporta
sem espessura.
"""

import bpy
import math
import os

SAIDA = os.path.join(
    os.path.dirname(os.path.abspath(bpy.data.filepath)),
    "..",
    "frontend",
    "public",
    "models",
    "mailer.glb",
)

FORA = {"Chao", "Camera", "Cam_Target", "L_Key", "L_Fill", "L_Rim"}

os.makedirs(os.path.dirname(SAIDA), exist_ok=True)

for ob in list(bpy.data.objects):
    if ob.name in FORA or ob.type in {"LIGHT", "CAMERA"}:
        bpy.data.objects.remove(ob, do_unlink=True)

# O glTF é Y-up e mede em metros; a cena do script é Z-up em milímetros. O
# exportador já converte o eixo, então aqui só resta garantir que ninguém
# ficou fora do frame de exportação.
bpy.context.view_layer.update()

"""
As barbatanas dobram ANTES da tampa descer, só na exportação.

No .blend elas dobram por último (250–272), o que faz sentido para uma
animação de montagem passo a passo. Mas o preview mostra a caixa PRONTA com a
tampa aberta, e nesse instante as barbatanas apareciam abertas no plano da
língua, como duas asas — que é o oposto do que a peça real mostra: elas são
vincadas, e quem monta a caixa já as dobra antes de fechar.

Aqui elas passam para a mesma janela das abas laterais da tampa (170–200), que
o próprio script já pré-dobra pela mesma razão. O .blend não é tocado.
"""
JANELA_BARBATANA = (170.0, 200.0)

for nome in ("V_BarbatanaD", "V_BarbatanaE"):
    ob = bpy.data.objects.get(nome)
    if ob is None or ob.animation_data is None:
        continue

    curvas = list(getattr(ob.animation_data.action, "fcurves", []))
    for layer in getattr(ob.animation_data.action, "layers", []):
        for strip in layer.strips:
            cb = strip.channelbag(ob.animation_data.action_slot)
            if cb is not None:
                curvas.extend(cb.fcurves)

    for fc in curvas:
        pontos = sorted(fc.keyframe_points, key=lambda k: k.co.x)
        if len(pontos) < 2:
            continue

        origem = (pontos[0].co.x, pontos[-1].co.x)
        destino = JANELA_BARBATANA

        def remapear(x, origem=origem, destino=destino):
            if origem[1] == origem[0]:
                return destino[0]
            k = (x - origem[0]) / (origem[1] - origem[0])
            return destino[0] + k * (destino[1] - destino[0])

        for kp in pontos:
            # As alças acompanham o quadro, senão a curva de easing entorta.
            for alca in ("handle_left", "handle_right"):
                h = getattr(kp, alca)
                h.x = remapear(h.x)
            kp.co.x = remapear(kp.co.x)

        fc.update()

# Os painéis nascem do bmesh sem UV, e sem UV a textura da matéria-prima
# escolhida na calculadora não tem onde pousar — a caixa ficaria sempre lisa.
# A projeção inteligente basta: são retângulos planos, não há costura difícil.
for ob in [o for o in bpy.data.objects if o.type == "MESH"]:
    bpy.context.view_layer.objects.active = ob
    ob.select_set(True)
    bpy.ops.object.mode_set(mode="EDIT")
    bpy.ops.mesh.select_all(action="SELECT")
    bpy.ops.uv.smart_project(angle_limit=math.radians(66.0), island_margin=0.02)
    bpy.ops.object.mode_set(mode="OBJECT")
    ob.select_set(False)

bpy.ops.export_scene.gltf(
    filepath=SAIDA,
    export_format="GLB",
    export_apply=True,          # aplica o SOLIDIFY: sem isso a chapa vai sem espessura
    export_animations=True,
    export_animation_mode="SCENE",
    export_bake_animation=True,  # os vincos são empties: sem bake não vão as poses
    export_frame_range=True,
    export_yup=True,
    export_cameras=False,
    export_lights=False,
    export_materials="EXPORT",
    export_normals=True,
    export_texcoords=True,
    export_extras=False,
)

tamanho = os.path.getsize(SAIDA)
print(f"[export] {SAIDA} — {tamanho / 1024:.0f} KB")
print(f"[export] frames {bpy.context.scene.frame_start}–{bpy.context.scene.frame_end}")
print(f"[export] objetos {len([o for o in bpy.data.objects if o.type == 'MESH'])} malhas, "
      f"{len([o for o in bpy.data.objects if o.type == 'EMPTY'])} vincos")
