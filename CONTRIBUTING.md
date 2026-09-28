# Contribuer à Doli2Shop

Merci de l'intérêt que vous portez à Doli2Shop ! Ce projet est édité par l'[Association P'tite Tête](https://www.ptitetete.org) (loi 1901) sous licence [GPL v3](LICENSE).

> 🇫🇷 Le code et la documentation principale sont en **français**. Les contributions en **anglais** sont les bienvenues — assurez-vous simplement que les descriptions de PR et issue restent intelligibles. Les commentaires de code peuvent être bilingues.

---

## 🐛 Signaler un bug

Avant d'ouvrir une issue :

1. Vérifier que le bug n'est pas déjà répertorié dans les [issues existantes](https://github.com/P-tite-tete/doli2shop/issues)
2. Reproduire avec la **dernière version stable** ([releases/latest](https://github.com/P-tite-tete/doli2shop/releases/latest))
3. Renseigner dans l'issue :
   - Version Dolibarr (ex: 19.0.3)
   - Version PHP (ex: 8.2)
   - Version du module (ex: v2.2.4)
   - Étapes de reproduction précises
   - Logs pertinents (Doli2Shop → Diagnostic → Logs)
   - Comportement attendu vs observé

---

## 💡 Proposer une feature

Ouvrir une issue avec le label `enhancement` décrivant :

- Le **besoin métier** : qui en bénéficie, quel cas d'usage
- La **solution envisagée** (optionnel — laissez de la place à la discussion technique)
- Les **alternatives** considérées

> ⚠️ Les features importantes sont priorisées par l'équipe P'tite Tête en fonction de la roadmap. **Demandez un avis avant d'investir du temps en code** sur une feature non triviale, pour éviter qu'une PR soit refusée pour des raisons de scope.

---

## 🚀 Soumettre une Pull Request

### Workflow

1. **Fork** le repo `P-tite-tete/doli2shop`
2. Créer une branche depuis `main` : `git checkout -b fix/description-courte` ou `feat/description-courte`
3. Coder, tester, committer en respectant les conventions ci-dessous
4. Push vers votre fork, ouvrir une **PR vers `main`**
5. Le mainteneur review, demande des ajustements éventuels, puis merge

### Conventions de commit

Format obligatoire :

```
type: Description concise

- Détail 1
- Détail 2
```

**Types autorisés** : `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`

**Exemples valides** :

```
fix: Corrige doublon produit après purge Shopify

- Vérifie l'existence du SKU avant création
- Ajoute test unitaire ProductImporterTest::testNoDuplicateAfterPurge
```

```
feat: Ajoute toggle stock virtuel par défaut

- Nouveau setting DOLI2SHOP_STOCK_VIRTUAL_DEFAULT
- Migration SQL idempotente via information_schema
```

⚠️ **Ne PAS ajouter** `Co-Authored-By:` ni `Generated with`. Les commits doivent être attribués à leur auteur humain.

### Conventions de code (PHP)

- **PSR-12** pour le style général
- **Docblock obligatoire** sur les classes et méthodes publiques avec : `@file`, `@brief`, `@author`, `@version`, `@since`, `@package`
- **Variables explicites** : `$dolibarrProductId`, `$shopifyVariantId` — jamais `$id` générique
- **API Shopify** : toujours **GraphQL** (l'API REST est dépréciée et bannie du projet)
- **SQL** : utiliser `information_schema` pour les ALTER conditionnels (jamais `IF NOT EXISTS` sur les colonnes/index)
- **Logs** : utiliser `LoggerTrait` (`$this->log('Message', LOG_INFO)`) — jamais loguer credentials ou données sensibles
- **Multi-entité** : toujours filtrer par `entity` dans les requêtes SQL

### 🔒 Hooks git (fortement recommandé)

Active les hooks pre-commit pour bloquer automatiquement les commits accidentels de secrets (DB passwords, tokens API, credentials, emails clients en PII, etc.) :

1. **Installer gitleaks** (scanner de secrets, v8.21+ requis) :
   - **macOS** : `brew install gitleaks`
   - **Linux** : binaire pré-compilé depuis [github.com/gitleaks/gitleaks/releases](https://github.com/gitleaks/gitleaks/releases) (extraire vers `/usr/local/bin/gitleaks`)
   - **Autres** : voir [gitleaks installation docs](https://github.com/gitleaks/gitleaks#installing)

2. **Activer les hooks du repo** (une seule fois par clone) :

   ```bash
   git config core.hooksPath .githooks
   ```

À partir de là, chaque `git commit` vérifie automatiquement :
- 🔍 **Garde chemins sensibles** (Story 61.5) — bloque `website/data/*.json|csv|bak|backup` (hors `*.example`) et tout chemin portant un numéro de série au format actif `SI-YYYY-XXXX-YYYY`, indépendamment du `.gitignore`
- 🔍 **Scan secrets via gitleaks** (Story 42.1) — pattern AWS, GitHub, Shopify, Stripe, password assignments, PII clients
- 🔍 **Audit public/privé** (Story 41.1) — pas de référence cassée entre code public et fichiers exclus

En cas de blocage, suivre les instructions affichées par le hook. Pour un faux positif gitleaks, ajouter un marker inline `# gitleaks:allow` sur la ligne concernée, OU enrichir l'allowlist dans `.gitleaks.toml`.

> 💡 **`jq` optionnel** : si installé (`brew install jq` macOS), le hook formate joliment le rapport de leak (sinon fallback `cat` raw).

### Tests requis

Avant de soumettre votre PR, vérifier que :

```bash
# Syntaxe PHP
php -l fichier_modifie.php

# Tests unitaires
php ./vendor/bin/phpunit

# Analyse statique
php -d memory_limit=512M ./vendor/bin/phpstan analyse
```

Les 3 commandes doivent passer.

### Traductions (5 langues)

Si la PR ajoute du **texte UI**, mettre à jour les **5 fichiers de langue** :

```
langs/fr_FR/doli2shop.lang
langs/en_US/doli2shop.lang
langs/de_DE/doli2shop.lang
langs/es_ES/doli2shop.lang
langs/it_IT/doli2shop.lang
```

Si vous ne maîtrisez pas toutes les langues, faire **FR + EN au minimum** — un mainteneur ou un autre contributeur complétera DE / ES / IT.

---

## 🧪 Tester localement

Setup de développement minimal :

```bash
# 1. Cloner et installer les dépendances
git clone https://github.com/P-tite-tete/doli2shop.git
cd doli2shop
composer install --dev

# 2. Lien symbolique vers une instance Dolibarr de dev
ln -s "$PWD" /chemin/vers/dolibarr/htdocs/custom/shopifyintegration

# 3. Activer le module dans Dolibarr → Configuration → Modules

# 4. Configurer une boutique Shopify de test (compte développeur Shopify gratuit)
```

Pour les tests d'intégration nécessitant Shopify, utiliser un **compte développeur Shopify** ([partners.shopify.com](https://partners.shopify.com)) — gratuit et illimité.

---

## 🤝 Code de conduite

Soyez respectueux. Le projet accueille toute contribution constructive, indépendamment de l'expérience, du genre, de l'origine, ou des opinions personnelles.

**Pas accepté** : agressivité, harcèlement, discussion politique / religieuse / commerciale dans les issues et PRs. Les violations entraînent la fermeture de la discussion et, en récidive, le bannissement du contributeur.

---

## 📋 Process de review

- Le mainteneur review **sous 7 jours** environ (selon disponibilité — projet bénévole)
- Les ajustements demandés se font via **commentaires GitHub** sur la PR
- Une fois mergée, la PR est intégrée à la prochaine release publique
- Les contributeurs significatifs sont mentionnés dans le `CHANGELOG.md`

> Les adhérents de l'association P'tite Tête bénéficient d'un canal de support direct par email pour leurs PRs prioritaires.

---

## 📜 Licence

En contribuant, vous acceptez que votre code soit publié sous **[GPL v3](LICENSE)**, conformément à la licence du projet.

---

Merci pour votre contribution ! 💙

— L'équipe Association P'tite Tête
