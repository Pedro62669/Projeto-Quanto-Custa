import bpy
import bmesh
import math
from mathutils import Matrix, Euler

# ----------------------------------------------------------------------------
# Caixa mailer roll-end auto-bloqueante (RETT).
# Unidades do script em mm; a cena trabalha em metros (1 BU = 1 m).
# ----------------------------------------------------------------------------
S = 0.001
T = 3.0                 # espessura do papelao (mm)

W, D = 300.0, 250.0     # base: largura (X) x profundidade (Y)
HW = 81.5               # altura de parede (roll, frente/tras e interna do roll)
BRIDGE = 2.0 * T        # ponte no topo do roll: engole 2 espessuras
INNER = HW              # parede interna do roll (desce ate o piso)
LOCK = 18.0             # lingueta de travamento no piso
TAB = 60.0              # avanco da aba lateral das paredes frente/tras
TAB_H = 81.5            # altura da aba lateral da parede (= HW)
FLAP = 70.0             # lingua frontal da tampa (desce por FORA da parede)
SIDE = 70.0             # abas laterais da tampa (descem por dentro)

# Barbatanas: tem vinco proprio e dobram 90 graus para dentro, como as abas
# laterais da tampa, alojando-se entre a aba da parede frontal e a parede
# interna do roll. Medidas em coordenadas da lingua (avanco, descida do vinco).
FIN_Y0 = 12.0           # topo da base da barbatana
FIN_Y1 = 40.0           # pe da base
FIN_NOSE_Y = 20.0       # altura do bico: alto, para o bordo de fuga varrer
FIN_OUT = 20.0          # avanco do bico = profundidade que entra na lateral
FIN_FOLD = 90.0         # dobra da barbatana

X_TAB = W / 2.0 - T / 2.0        # 148.5 -> plano da aba lateral
X_INNER = W / 2.0 - BRIDGE       # 144.0 -> plano da parede interna
X_TAB_HINGE = X_TAB - T / 2.0    # 147.0 -> vinco da aba (encaixa no bolso)
X_LID = W / 2.0 - BRIDGE - T     # 141.0 -> borda da tampa / plano da aba lateral
X_FLAP = X_LID                   # lingua na largura da tampa; barbatana vinca aqui
# A parede frontal recua para dar passagem a' barbatana, que desce no plano
# X_LID. Sua aba lateral entao vinca em X_WALL_F e nao alcanca o bolso do roll
# -- e' de proposito: a barbatana e' que ocupa a folga, presa entre a aba e a
# parede interna do roll. A parede traseira segue larga, com aba no bolso.
X_WALL_F = X_LID - T             # 138.0
Y_INNER = D / 2.0 - T / 2.0      # 123.5 -> parede interna encosta nas paredes
# A lingua desce por FORA: plano T alem da face externa da parede frontal.
Y_FLAP = -(D / 2.0 + T)          # -128.0
LID = D / 2.0 - Y_FLAP           # 253.0 -> tampa vai do vinco traseiro ate la

Z_FLAP = HW + T / 2.0            # 83.0 -> altura do vinco da lingua

SLOT_X = (W / 2.0 - BRIDGE - LOCK, W / 2.0 - BRIDGE)   # (126, 144)
SLOT_Y = ((45.0, 100.0), (-100.0, -45.0))

log = []

# --- limpeza -----------------------------------------------------------------
for nm in ("Cube", "Light"):
    ob = bpy.data.objects.get(nm)
    if ob is not None:
        bpy.data.objects.remove(ob, do_unlink=True)

old = bpy.data.collections.get("Mailer")
if old is not None:
    for ob in list(old.objects):
        bpy.data.objects.remove(ob, do_unlink=True)
    bpy.data.collections.remove(old)

col = bpy.data.collections.new("Mailer")
bpy.context.scene.collection.children.link(col)

# --- material ----------------------------------------------------------------
mat = bpy.data.materials.get("Kraft") or bpy.data.materials.new("Kraft")
mat.use_nodes = True
nt = mat.node_tree
nt.nodes.clear()
out = nt.nodes.new("ShaderNodeOutputMaterial")
out.location = (500, 0)
bsdf = nt.nodes.new("ShaderNodeBsdfPrincipled")
bsdf.location = (200, 0)
bsdf.inputs["Base Color"].default_value = (0.361, 0.216, 0.106, 1.0)
bsdf.inputs["Roughness"].default_value = 0.88
# Coordenada de objeto, nao Generated: Generated e' normalizada pela caixa
# delimitadora de cada objeto, o que daria fibra grossa na base e fina nas
# abas. Em coordenada de objeto a escala e' metrica e igual em todo painel.
texco = nt.nodes.new("ShaderNodeTexCoord")
texco.location = (-520, -220)
noise = nt.nodes.new("ShaderNodeTexNoise")
noise.location = (-300, -220)
noise.inputs["Scale"].default_value = 900.0
noise.inputs["Detail"].default_value = 4.0
bump = nt.nodes.new("ShaderNodeBump")
bump.location = (-60, -220)
bump.inputs["Strength"].default_value = 0.10
nt.links.new(texco.outputs["Object"], noise.inputs["Vector"])
nt.links.new(noise.outputs["Fac"], bump.inputs["Height"])
nt.links.new(bump.outputs["Normal"], bsdf.inputs["Normal"])
nt.links.new(bsdf.outputs["BSDF"], out.inputs["Surface"])

# --- construtores ------------------------------------------------------------


def mesh_from_cells(name, cells, z0=0.0):
    """Malha plana a partir de celulas retangulares (x0,x1,y0,y1)."""
    me = bpy.data.meshes.new(name)
    bm = bmesh.new()
    vmap = {}

    def V(x, y):
        k = (round(x, 4), round(y, 4))
        v = vmap.get(k)
        if v is None:
            v = bm.verts.new((x * S, y * S, z0 * S))
            vmap[k] = v
        return v

    for (x0, x1, y0, y1) in cells:
        bm.faces.new((V(x0, y0), V(x1, y0), V(x1, y1), V(x0, y1)))
    bm.normal_update()
    bm.to_mesh(me)
    bm.free()
    return me


def mesh_from_poly(name, pts, z0=0.0):
    """Malha plana a partir de um contorno fechado (n-gon)."""
    me = bpy.data.meshes.new(name)
    bm = bmesh.new()
    vs = [bm.verts.new((x * S, y * S, z0 * S)) for (x, y) in pts]
    bm.faces.new(vs)
    bm.normal_update()
    bm.to_mesh(me)
    bm.free()
    return me


def bez(p0, p1, p2, n=12):
    """Bezier quadratica, sem o ponto inicial (evita vertice duplicado)."""
    out = []
    for i in range(1, n + 1):
        t = i / n
        s = 1.0 - t
        out.append((s * s * p0[0] + 2 * s * t * p1[0] + t * t * p2[0],
                    s * s * p0[1] + 2 * s * t * p1[1] + t * t * p2[1]))
    return out


def grid_with_holes(xs, ys, holes):
    """Celulas de uma grade, omitindo as que caem dentro de um furo."""
    cells = []
    for i in range(len(xs) - 1):
        for j in range(len(ys) - 1):
            cx0, cx1, cy0, cy1 = xs[i], xs[i + 1], ys[j], ys[j + 1]
            inside = any(
                hx0 <= cx0 and cx1 <= hx1 and hy0 <= cy0 and cy1 <= hy1
                for (hx0, hx1, hy0, hy1) in holes
            )
            if not inside:
                cells.append((cx0, cx1, cy0, cy1))
    return cells


def add_hinge(name, parent, loc, rz_deg=0.0):
    """Vinco: empty cujo +Y local aponta para onde o painel se estende.

    Toda dobra e' +90 graus em torno do X local -- regra uniforme da cadeia.
    """
    e = bpy.data.objects.new(name, None)
    e.empty_display_type = 'ARROWS'
    e.empty_display_size = 0.025
    col.objects.link(e)
    if parent is not None:
        e.parent = parent
        e.matrix_parent_inverse = Matrix.Identity(4)
    e.location = (loc[0] * S, loc[1] * S, loc[2] * S)
    e.rotation_euler = Euler((0.0, 0.0, math.radians(rz_deg)), 'XYZ')
    return e


def add_panel(name, hinge, cells=None, poly=None, z0=0.0):
    me = (mesh_from_poly(name, poly, z0) if poly is not None
          else mesh_from_cells(name, cells, z0))
    ob = bpy.data.objects.new(name, me)
    col.objects.link(ob)
    if hinge is not None:
        ob.parent = hinge
        ob.matrix_parent_inverse = Matrix.Identity(4)
    m = ob.modifiers.new("Espessura", 'SOLIDIFY')
    m.thickness = T * S
    m.offset = 0.0
    ob.data.materials.append(mat)
    return ob


def rect(x0, x1, y0, y1):
    return [(x0, x1, y0, y1)]


hinges = {}


def H(name, parent, loc, rz=0.0):
    hinges[name] = add_hinge("V_" + name, parent, loc, rz)
    return hinges[name]


# --- perfil da barbatana ------------------------------------------------------
# Coordenadas da lingua: (avanco para fora, descida a partir do vinco).
# Bordo de ataque quase reto ate o bico, bordo de fuga concavo varrendo para
# tras -- e' isso que da' o desenho de barbatana em vez de meia-lua.
_nose = (FIN_OUT, FIN_NOSE_Y)
FIN_BORDA = [(0.0, FIN_Y0)]
FIN_BORDA += bez((0.0, FIN_Y0), (FIN_OUT - 7.0, FIN_Y0), _nose, n=20)
FIN_BORDA += bez(_nose, (FIN_OUT - 13.0, FIN_Y1 - 6.0), (0.0, FIN_Y1), n=20)

# --- raiz + base -------------------------------------------------------------
root = add_hinge("Mailer_Root", None, (0.0, 0.0, 0.0))

xs = sorted({-W / 2, W / 2, SLOT_X[0], SLOT_X[1], -SLOT_X[0], -SLOT_X[1]})
ys = sorted({-D / 2, D / 2} | {v for pair in SLOT_Y for v in pair})
holes = []
for (sy0, sy1) in SLOT_Y:
    holes.append((SLOT_X[0], SLOT_X[1], sy0, sy1))
    holes.append((-SLOT_X[1], -SLOT_X[0], sy0, sy1))
base = add_panel("Base", root, grid_with_holes(xs, ys, holes))

# --- paredes frente / tras + abas laterais -----------------------------------
# A aba tem altura TAB_H (< HW) de proposito: o topo do bolso do roll fica
# vazio, e e' nele que a orelha da tampa entra e trava.
for side, y_edge, rz, xw in (("Frente", -D / 2, 180.0, X_WALL_F),
                             ("Tras", D / 2, 0.0, X_TAB_HINGE)):
    hw = H("Parede" + side, root, (0.0, y_edge, 0.0), rz)
    add_panel("Parede" + side, hw, rect(-xw, xw, 0.0, HW))
    # Abas: +Y local da aba corre ao longo de X da parede; X local cobre a altura.
    ht = H("Aba" + side + "D", hw, (xw, 0.0, 0.0), -90.0)
    add_panel("Aba" + side + "D", ht, rect(-TAB_H, 0.0, 0.0, TAB))
    ht = H("Aba" + side + "E", hw, (-xw, 0.0, 0.0), 90.0)
    add_panel("Aba" + side + "E", ht, rect(0.0, TAB_H, 0.0, TAB))

# --- tampa: tras -> tampa -> (abas laterais) -> flap+orelhas -> lingueta ------
# O vinco da tampa fica T/2 alem do topo da parede traseira. E' a folga de
# dobra: na faca vira 1.5 mm de material a mais, e com a caixa fechada e' o
# que faz a tampa assentar sobre a parede em vez de atravessa-la.
hl = H("Tampa", hinges["ParedeTras"], (0.0, HW + T / 2.0, 0.0))
add_panel("Tampa", hl, rect(-X_LID, X_LID, 0.0, LID))

# Abas laterais da tampa: descem por dentro, encostando na parede interna do
# roll. Recuadas na frente e chanfradas para liberar o bolso das orelhas.
# Em Y da tampa: 5 encosta na parede traseira; 230 (= y -105) para antes das
# barbatanas, que ocupam o mesmo plano na frente da caixa.
SIDE_A, SIDE_B = 5.0, 230.0
for side, x_lid, rz, sg in (("D", X_LID, -90.0, -1.0), ("E", -X_LID, 90.0, 1.0)):
    hs = H("AbaTampa" + side, hl, (x_lid, 0.0, 0.0), rz)
    a, b = sg * SIDE_A, sg * SIDE_B
    add_panel("AbaTampa" + side, hs, poly=[
        (a, 0.0), (b, 0.0), (b, SIDE - 25.0), (b - sg * 18.0, SIDE), (a, SIDE),
    ])

# Lingua frontal: desce por FORA da parede. Retangulo simples; o que trava sao
# as barbatanas nas pontas.
hf = H("FlapTampa", hl, (0.0, LID, 0.0))
add_panel("FlapTampa", hf, rect(-X_FLAP, X_FLAP, 0.0, FLAP))

# Barbatanas. Coplanares com a lingua na faca, mas objetos proprios: no fim da
# animacao giram FIN_FLEX em torno de um eixo vertical para entrar na fresta.
# Isso e' o papelao cedendo no encaixe, nao um vinco cortado.
for side, sg, rz in (("D", 1.0, -90.0), ("E", -1.0, 90.0)):
    hb = H("Barbatana" + side, hf, (sg * X_FLAP, 0.0, 0.0), rz)
    # No lado E o X local do vinco aponta para baixo; no D, para cima.
    add_panel("Barbatana" + side, hb,
              poly=[(-sg * d, v) for (v, d) in FIN_BORDA])

# --- roll ends: externa -> ponte -> interna (recortada) -> travas ------------
for side, x_edge, rz in (("D", W / 2, -90.0), ("E", -W / 2, 90.0)):
    ho = H("RollExt" + side, root, (x_edge, 0.0, 0.0), rz)
    add_panel("RollExt" + side, ho, rect(-D / 2, D / 2, 0.0, HW))
    hb = H("RollPonte" + side, ho, (0.0, HW, 0.0))
    add_panel("RollPonte" + side, hb, rect(-D / 2, D / 2, 0.0, BRIDGE))
    hi = H("RollInt" + side, hb, (0.0, BRIDGE, 0.0))
    add_panel("RollInt" + side, hi, rect(-Y_INNER, Y_INNER, 0.0, INNER))
    for k, (sy0, sy1) in enumerate(SLOT_Y):
        hk = H("Trava%s%d" % (side, k), hi, (0.0, INNER, 0.0))
        # X local do vinco = -Y do mundo no lado D (e +Y no lado E); simetrico.
        add_panel("Trava%s%d" % (side, k), hk, rect(-sy1, -sy0, 0.0, LOCK))

# --- animacao ----------------------------------------------------------------
scene = bpy.context.scene
scene.render.fps = 24
scene.frame_start = 1
scene.frame_end = 330

# (vinco, frame inicial, frame final, angulo). As travas sao dobra reversa:
# o roll gira +90 tres vezes (sobe, atravessa, desce) e a lingueta volta -90
# para entrar na fresta do piso em vez de continuar o enrolamento para fora.
TIMELINE = [
    ("ParedeFrente", 20, 55, 90.0), ("ParedeTras", 20, 55, 90.0),
    ("AbaFrenteD", 50, 80, 90.0), ("AbaFrenteE", 50, 80, 90.0),
    ("AbaTrasD", 50, 80, 90.0), ("AbaTrasE", 50, 80, 90.0),
    ("RollExtD", 80, 118, 90.0), ("RollExtE", 80, 118, 90.0),
    ("RollPonteD", 112, 138, 90.0), ("RollPonteE", 112, 138, 90.0),
    ("RollIntD", 132, 170, 90.0), ("RollIntE", 132, 170, 90.0),
    # As travas viram enquanto a parede interna ainda desce (que termina em
    # 170). Se esperarem ela chegar, apontam reto para baixo e atravessam o
    # piso -- chegavam a -18 mm com o tempo antigo, 166 a 192.
    ("TravaD0", 136, 158, -90.0), ("TravaD1", 136, 158, -90.0),
    ("TravaE0", 136, 158, -90.0), ("TravaE1", 136, 158, -90.0),
    # As abas laterais sao pre-dobradas com a tampa ainda de pe, como se faz a
    # mao: terminam em 200 e so' entao a tampa comeca a descer.
    ("AbaTampaD", 170, 200, 90.0), ("AbaTampaE", 170, 200, 90.0),
    ("Tampa", 206, 246, 90.0),
    # Barbatanas pre-dobradas com a lingua ainda na horizontal, como as abas
    # laterais: descem junto com ela e entram na lateral.
    ("BarbatanaD", 250, 272, FIN_FOLD), ("BarbatanaE", 250, 272, FIN_FOLD),
    ("FlapTampa", 278, 306, 90.0),
]


def fcurves_of(ob):
    ad = ob.animation_data
    act = ad.action
    fcs = list(getattr(act, "fcurves", []))
    if fcs:
        return fcs
    for layer in getattr(act, "layers", []):
        for strip in layer.strips:
            try:
                cb = strip.channelbag(ad.action_slot)
            except Exception:
                cb = None
            if cb is not None:
                fcs.extend(cb.fcurves)
    return fcs


for name, f0, f1, ang in TIMELINE:
    h = hinges[name]
    h.rotation_euler.x = 0.0
    h.keyframe_insert("rotation_euler", index=0, frame=f0)
    h.rotation_euler.x = math.radians(ang)
    h.keyframe_insert("rotation_euler", index=0, frame=f1)
    for fc in fcurves_of(h):
        for kp in fc.keyframe_points:
            kp.interpolation = 'BEZIER'
            kp.easing = 'EASE_IN_OUT'

# --- cena: chao, luzes, camera, unidades -------------------------------------
floor = add_panel("Chao", None, rect(-1200.0, 1200.0, -1200.0, 1600.0))
floor.modifiers.clear()
floor.location.z = -T / 2.0 * S - 0.0002
floor.data.materials.clear()
fmat = bpy.data.materials.get("Piso") or bpy.data.materials.new("Piso")
fmat.use_nodes = True
fbsdf = fmat.node_tree.nodes.get("Principled BSDF")
if fbsdf is not None:
    fbsdf.inputs["Base Color"].default_value = (0.55, 0.55, 0.57, 1.0)
    fbsdf.inputs["Roughness"].default_value = 0.6
floor.data.materials.append(fmat)

for nm, loc, energy, size in (
    ("Key", (0.9, -0.9, 1.3), 260.0, 1.2),
    ("Fill", (-1.1, -0.6, 0.6), 70.0, 1.6),
    ("Rim", (-0.3, 1.4, 0.9), 110.0, 1.0),
):
    ld = bpy.data.lights.new("L_" + nm, 'AREA')
    ld.energy = energy
    ld.size = size
    lo = bpy.data.objects.new("L_" + nm, ld)
    col.objects.link(lo)
    lo.location = loc
    lo.rotation_euler = (0.0, 0.0, 0.0)
    c = lo.constraints.new('TRACK_TO')
    c.target = root
    c.track_axis = 'TRACK_NEGATIVE_Z'
    c.up_axis = 'UP_Y'

cam = bpy.data.objects.get("Camera")
if cam is None:
    cam = bpy.data.objects.new("Camera", bpy.data.cameras.new("Camera"))
    scene.collection.objects.link(cam)
cam.location = (0.75, -0.95, 0.62)
cam.rotation_euler = (0.0, 0.0, 0.0)
cam.data.lens = 35.0
target = add_hinge("Cam_Target", None, (0.0, 100.0, 20.0))
for c in list(cam.constraints):
    cam.constraints.remove(c)
c = cam.constraints.new('TRACK_TO')
c.target = target
c.track_axis = 'TRACK_NEGATIVE_Z'
c.up_axis = 'UP_Y'
scene.camera = cam

scene.unit_settings.system = 'METRIC'
scene.unit_settings.length_unit = 'MILLIMETERS'
scene.render.resolution_x = 1920
scene.render.resolution_y = 1080
scene.world.use_nodes = True
bg = scene.world.node_tree.nodes.get("Background")
if bg is not None:
    bg.inputs[0].default_value = (0.05, 0.055, 0.065, 1.0)
    bg.inputs[1].default_value = 1.0

n_painel = len([o for o in col.objects if o.type == 'MESH'])
result = {
    "paineis": n_painel,
    "vincos": len(hinges),
    "faca_mm": {"x": 2 * (W / 2 + HW + BRIDGE + INNER + LOCK),
                "y": HW + D + HW + LID + FLAP},
    "externo_mm": [W + T, D + T, HW + T + T / 2],
    "interno_mm": [2 * (X_INNER - T / 2), D - T, HW - T / 2],
    "frames": [scene.frame_start, scene.frame_end],
    "duracao_s": round((scene.frame_end - scene.frame_start) / scene.render.fps, 1),
}
