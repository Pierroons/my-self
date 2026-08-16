# Style markdownlint de ce dépôt — chargé par .mdlrc.
#
# Deux écarts au jeu par défaut, et seulement deux : le reste des règles
# s'applique tel quel.

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
# ne peuvent pas se replier. Une règle qui crie sur du travail correct finit par
# faire ignorer le rapport entier.
exclude_rule "MD013"
