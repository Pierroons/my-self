"""SelfJustice — serveur MCP de consultation juridique.

⚠️ L'objet `MCPServer` n'est volontairement pas ré-exporté ici : il s'appelle
`server`, comme le sous-module qui le contient, et le ré-exporter masquerait
`selfright_mcp.server` pour tout code qui l'importe par son chemin complet.
Pour y accéder : `from selfright_mcp.server import server`.
"""

# 🔑 Une seule source de vérité : `pyproject.toml`. Recopier le numéro ici en
# ferait une seconde, et deux versions finissent toujours par diverger — le pied
# de page annonçait 0.1.0-dev quand le tag disait 0.1.1, sur un autre module.
try:
    from importlib.metadata import version as _v
    __version__ = _v("selfright-mcp")
except Exception:                      # paquet non installé (exécution sur les sources)
    __version__ = "0.0.0+source"

# `server` lit `__version__` : il s'importe donc APRÈS sa définition.
from .server import main

__all__ = ["main"]
