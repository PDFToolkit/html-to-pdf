# Niveaux CSS et qualification

[English](../css-levels.md)

La version 0.2 constitue une **base native HTML/CSS vers PDF encore incomplète**. Elle ne revendique pas une prise en charge complète de CSS1, CSS2, CSS 2.1 ou « CSS3 ». Accepter le nom d’une propriété ne prouve pas que toutes ses valeurs, ses éléments d’application, son héritage, ses interactions de mise en page et ses règles d’erreur fonctionnent.

Le travail suit cet ordre :

1. Inventorier et qualifier CSS1, notamment ses propriétés, sélecteurs, cascade, héritage, formatage, sélection des polices, unités, reprise après erreur, imports et comportement du fond de page.
2. Étudier les ajouts du CSS2 historique et leur portée une fois l’étape CSS1 qualifiée.
3. Terminer les corrections et définitions révisées de CSS 2.1, en vérifiant les régressions des étapes précédentes.
4. Choisir les modules CSS ultérieurs selon leur propre version et leurs exigences applicables. Un module de niveau 3 ou 4 ne signifie pas que tout CSS est implémenté.

Il s’agit d’un ordre d’implémentation et de revue, sans reproduire volontairement des erreurs anciennes. Les recommandations historiques [CSS1](https://www.w3.org/TR/2008/REC-CSS1-20080411/) et [CSS2](https://www.w3.org/TR/2008/REC-CSS2-20080411/) renvoient les implémenteurs aux définitions communes corrigées dans CSS 2.1. La recommandation [CSS 2.1](https://www.w3.org/TR/2011/REC-CSS2-20110607/) remplace CSS2 et identifie les fonctionnalités modifiées ou retirées. Le [CSS Snapshot 2025](https://www.w3.org/TR/2025/NOTE-css-2025-20250918/) rassemble les spécifications ultérieures développées séparément ; il ne remplace pas leurs exigences individuelles.

Le [registre de couverture](../../resources/css-coverage.json), exploitable par les outils, inventorie actuellement **53 noms de propriétés CSS1 et neuf groupes de comportements**. Ses 62 entrées comptent 45 états `partial` et 17 états `unsupported` ; aucune n’est entièrement qualifiée. L’inventaire des noms de propriétés est complet. La qualification par valeur et contexte ne l’est pas. Les inventaires CSS2, CSS 2.1 et modules ultérieurs restent incomplets, et toutes les étapes portent `claimedComplete: false`.

Chaque entrée possède un identifiant stable, une référence normative, une note de portée et des chemins de tests réels. Les propriétés indiquent aussi une valeur témoin et un contexte HTML. `partial` signifie qu’un sous-ensemble documenté existe ; `unsupported` indique que la fonctionnalité recensée manque. Un futur état `implemented` demandera encore une qualification indépendante. `validated` est réservé à une exigence qualifiée, avec ses valeurs et contextes. Modifier une étiquette ne suffit pas à qualifier un niveau.

Les [tests paramétrés des propriétés](../../tests/Css/Css1RegistryTest.php) analysent chaque échantillon avec un jeu de polices Noto fourni explicitement. Un échantillon partiel doit produire le champ calculé attendu sans diagnostic. Un échantillon non pris en charge doit lever `UnsupportedFeature` avec la politique par défaut. Le témoin `vertical-align` utilise une cellule de tableau valide : cette extension ne démontre **pas** la prise en charge de l’alignement inline de CSS1. Ces tests ne rendent pas toutes les propriétés, n’énumèrent pas toutes leurs valeurs légales et n’établissent pas une conformité. Les autres tests de cascade, analyse, mise en page, polices, PDF et navigateurs apportent des preuves supplémentaires avec leurs propres limites.

Pour vérifier le registre et les fichiers référencés :

```sh
python3 tools/check_css_coverage.py --report _build/css-coverage.json
python3 tools/test_css_coverage.py
```

Un registre cohérent mais incomplet renvoie le code 0 avec `status: "pass"`, `incomplete: true`, `complete: false` et `witnessesAreFullQualification: false`. Ce résultat concerne la cohérence des déclarations. L’outil n’exécute pas les tests PHP, ne rend pas les PDF et ne remplace pas les reçus de qualification.

Une version souhaitant revendiquer une étape complète doit ajouter cette vérification bloquante :

```sh
python3 tools/check_css_coverage.py --require-stage css1 --report _build/css1-gate.json
```

Pour la version 0.2, **le code 1 et `status: "incomplete"` sont le résultat attendu**. Les constructions normales peuvent publier cette base partielle présentée honnêtement ; elles ne doivent pas assimiler sa publication à une qualification CSS1 complète. La vérification d’étape est obligatoire pour formuler cette revendication, pas une option permettant de masquer une conformité échouée.

L’outil refuse les identifiants dupliqués, propriétés CSS1 manquantes ou remplacées, états inconnus, tests absents, chemins extérieurs au dépôt et revendications prématurées. Une étape complète exige un inventaire complet non vide, toutes ses exigences `validated`, un reçu complet dont l’empreinte concorde et qui couvre valeurs et contextes, ainsi que la qualification des étapes précédentes. Un reçu de simple témoin d’analyse est explicitement insuffisant. Les [tests négatifs](../../tools/test_css_coverage.py) vérifient ces distinctions, y compris le vrai code de sortie non nul de la commande pour CSS1 incomplet.

La qualification future devra détailler les valeurs et contextes de chaque propriété, inclure les entrées valides et invalides, l’héritage et les interactions des raccourcis, comparer des preuves indépendantes de mise en page et de rendu, puis conserver les reçus de versions exactes. Par exemple, `margin-top: 6pt` et la fusion de marges positives ne démontrent pas les marges négatives, en pourcentage ou `auto` ; un fond uni de boîte ne démontre pas sa propagation depuis html/body au fond de page ; quatre variantes de police fournies ne démontrent pas la sélection CSS des familles. Ces lacunes restent visibles dans le registre jusqu’à la fin de leur implémentation et de leur validation.


Le jalon 0.2 traite la validation des valeurs originales, les imports explicites
ordonnés, certaines récupérations syntaxiques, les sélecteurs échappés et les
côtés de bordure indépendants ; l’[exemple CSS1](css1.md) rend visible ce dernier
sous-ensemble. Le prochain chantier CSS1 doit porter sur le texte et les polices :
sélection des familles/variantes, raccourci font, espacements, retrait et
transformation, avec témoins indépendants de placement et d’extraction du texte.
Flottants, autres contextes de boîtes et fond de page demandent encore leurs
propres travaux avant toute revendication complète. La qualification de release
identifie le candidat exact ; les comptes du registre ne remplacent pas ses reçus.
