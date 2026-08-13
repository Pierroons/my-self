"""SelfJustice — serveur MCP de consultation juridique.

⚠️ L'objet `MCPServer` n'est volontairement pas ré-exporté ici : il s'appelle
`server`, comme le sous-module qui le contient, et le ré-exporter masquerait
`selfright_mcp.server` pour tout code qui l'importe par son chemin complet.
Pour y accéder : `from selfright_mcp.server import server`.
"""

from .server import main

__all__ = ["main"]
__version__ = "0.1.0"
