from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.boundsPen import BoundsPen

FONT = r"D:/github/panel-flexyflow-co/bistro/frontend/public/fonts/FlexyFont.otf"
BR   = r"D:/github/panel-flexyflow-co/bistro/branding"
DARK="#1E232E"; WHITE="#f6f5f3"
MARGIN=0.05                    # 5% top & bottom -> glyph = 90% of height

font = TTFont(FONT); gs = font.getGlyphSet(); cmap = font.getBestCmap()
g = gs[cmap[ord("b")]]
bp=BoundsPen(gs); g.draw(bp); x0,y0,x1,y1 = bp.bounds
gw, gh = x1-x0, y1-y0
sp=SVGPathPen(gs); g.draw(sp); D = sp.getCommands()

def _place(size):
    # Centra el ink-bbox del glifo exacto en ambos ejes (independiente de que la
    # baseline sea 0: usa y1 real). SVG y-down: (px,py) -> (a + s*px, b - s*py).
    ratio = 1 - 2*MARGIN
    s = ratio*size/gh
    a = (size - gw*s)/2 - s*x0
    b = (size - gh*s)/2 + s*y1
    return f"translate({a:.2f},{b:.2f}) scale({s:.5f},-{s:.5f})"

def icon(bg, fg, size=500, radius_ratio=0.22):
    r = radius_ratio*size
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {size} {size}" width="{size}" height="{size}" role="img" aria-label="bistro">
  <title>bistro</title>
  <rect width="{size}" height="{size}" rx="{r:.0f}" ry="{r:.0f}" fill="{bg}"/>
  <g transform="{_place(size)}" fill="{fg}"><path d="{D}"/></g>
</svg>
'''

def favicon_adaptive(size=500, radius_ratio=0.22):
    # Default (light): white bg + dark letter. Dark theme inverts.
    r = radius_ratio*size
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {size} {size}" width="{size}" height="{size}" role="img" aria-label="bistro">
  <title>bistro</title>
  <style>
    .bg {{ fill: {WHITE}; }}
    .fg {{ fill: {DARK}; }}
    @media (prefers-color-scheme: dark) {{
      .bg {{ fill: {DARK}; }}
      .fg {{ fill: {WHITE}; }}
    }}
  </style>
  <rect class="bg" width="{size}" height="{size}" rx="{r:.0f}" ry="{r:.0f}"/>
  <g class="fg" transform="{_place(size)}"><path d="{D}"/></g>
</svg>
'''

def mark(fill):
    m = gh*MARGIN/(1-2*MARGIN)
    H = gh + 2*m; W = gw + 2*m
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W:.0f} {H:.0f}" fill="none" role="img" aria-label="bistro">
  <g transform="translate({m - x0:.2f},{y1 + m:.2f}) scale(1,-1)" fill="{fill}"><path d="{D}"/></g>
</svg>
'''

# Static PNG masters: DEFAULT look = white bg + dark letter
open(BR+"/icon-source.svg","w",encoding="utf-8").write(icon(WHITE, DARK))
open(BR+"/icon-maskable.svg","w",encoding="utf-8").write(icon(WHITE, DARK, radius_ratio=0.0))
# Web favicon: theme-adaptive
open(BR+"/favicon.svg","w",encoding="utf-8").write(favicon_adaptive())
# Standalone bare marks (explicit color variants, not deployed)
open(BR+"/bistro-logo-dark.svg","w",encoding="utf-8").write(mark(DARK))
open(BR+"/bistro-logo-white.svg","w",encoding="utf-8").write(mark(WHITE))
print("default icons = white bg + dark b; favicon.svg = adaptive (light default)")
