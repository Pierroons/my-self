# Style markdownlint de ce dépôt — chargé par .mdlrc.
#
# Toutes les règles de mdl s'appliquent, sauf les écarts ci-dessous. Chacun
# porte sa raison : une règle qui crie sur du travail correct finit par faire
# ignorer le rapport entier.

# `all` d'abord : un style qui nomme des règles sans cette ligne n'active que
# celles-là. `mdl -l` liste ce qui tourne réellement.
all

# MD029 — numérotation des listes ordonnées.
#
# Le défaut de mdl exige que chaque item porte « 1. », et signale donc « 1. 2. 3. »
# comme une faute. Les deux formes sont valides en Markdown et rendues à
# l'identique. Renuméroter des listes correctes pour satisfaire une préférence
# d'outil abîmerait la source sans rien améliorer à la lecture.
rule "MD029", :style => :one_or_ordered

# MD013 — longueur de ligne.
#
# 17 des 27 constats du dépôt au 14/08/2026, presque tous sur des tableaux, qui
# ne peuvent pas se replier.
exclude_rule "MD013"

# MD007 — indentation des sous-listes : deux espaces, comme GitHub les rend.
# Le défaut de mdl en attend trois.
rule "MD007", :indent => 2

# MD024 — titres en double : seulement entre frères. Le CHANGELOG répète
# « Vérification » sous des versions différentes, et c'est voulu.
rule "MD024", :allow_different_nesting => true

# MD002, MD041 — premier titre en tête de fichier. mdl ne lit pas un en-tête
# YAML (agents, modèles d'issue, whitepaper SelfAct) et y voit un fichier sans
# titre.
exclude_rule "MD002"
exclude_rule "MD041"

# MD026 — ponctuation en fin de titre. Un titre français finit souvent par
# « : » ou « ? ».
exclude_rule "MD026"

# MD036 — emphase employée comme titre. Une ligne seule en gras ou en italique
# est ici un sous-titre, une mention d'édition ou une étiquette, pas un titre
# manqué.
exclude_rule "MD036"

# MD028 — ligne vide dans une citation : deux encadrés qui se suivent sont deux
# encadrés, et GitHub les rend ainsi.
exclude_rule "MD028"

# MD055, MD056, MD057 — forme des tableaux. mdl lit mal un tableau placé dans
# une citation et compte le `\|` échappé d'un bloc de code comme une colonne :
# ses constats du dépôt étaient tous faux. Un tableau réellement cassé se voit
# au rendu.
exclude_rule "MD055"
exclude_rule "MD056"
exclude_rule "MD057"
