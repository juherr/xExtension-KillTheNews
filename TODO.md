# TODO — xExtension-KillTheNews

## 🔴 Problèmes à corriger

- [x] **`handleConfigureAction()` appelle `parent::init()`** — devrait appeler `parent::handleConfigureAction()`. `registerTranslates()` est déjà appelé dans `init()`, donc le double appel est redondant et la sémantique est incorrecte. (`extension.php:79`)
- [x] **`$apiToken` est un champ public** — peuplé avec le vrai token à la ligne 107 pour la vue `configure.phtml`. La vue n'a besoin que de savoir si un token est configuré (affichage `••••••••`), pas de la valeur. Rendre le champ privé ou `readonly`. (`extension.php:10`)
- [x] **Pas de validation de l'URL dans `normalizeBaseUrl`** — la regex `^https?://` accepte n'importe quel host (risque SSRF partiel). Ajouter `filter_var($url, FILTER_VALIDATE_URL)` après normalisation. (`KillTheNewsClient.php:60-69`)
- [x] **Les deux vues sont identiques** — `views/killthenews/create.phtml` et `views/killthenews/list.phtml` sont strictement identiques. Fusionner en une vue partagée `views/killthenews/json.phtml` et mettre à jour le contrôleur.

## 🟡 Améliorations importantes

- [x] **`CATEGORY_NAME` hardcodé en anglais** — la catégorie `'Newsletters'` est créée en anglais même pour les utilisateurs francophones. Soit passer par `_t('ext.kill_the_news.category_name')` (+ ajouter la clé dans les deux locales), soit rendre la valeur configurable dans les settings. (`Controllers/killthenewsController.php:6`)
- [x] **`metadata.json` incomplet** — ajouter `minFreshRSS` (ex. `"1.21.0"`) et `homepage` (`"https://github.com/juherr/xExtension-KillTheNews"`). Sans `minFreshRSS`, l'extension peut être activée sur des versions incompatibles. (`metadata.json`)
- [x] **`atomUrl` parsé mais jamais utilisé** — présent dans le shape de retour mais ni le contrôleur ni le JS ne l'utilisent. Décider : documenter l'intention future ou retirer pour simplifier. (`KillTheNewsClient.php:166`)
- [x] **Pas de support de la touche Entrée dans le panneau** — ajouter un listener `keydown` sur `nameInput` pour déclencher `createBtn.click()` quand `e.key === 'Enter'`. (`static/kill-the-news.js:38`)
- [x] **Pas de `<label>` visible pour le champ de saisie** — le champ n'a qu'un `aria-label`, pas de label visible. Ajouter un `<label>` améliore l'accessibilité et permet le clic pour focus. (`static/kill-the-news.js:23`)
- [x] **Pas de fallback pour `navigator.clipboard`** — indisponible en HTTP (dev local, instance non-sécurisée). Ajouter un `.catch()` avec sélection manuelle ou message d'erreur. (`static/kill-the-news.js:95`)
- [x] **Utiliser `.finally()` au lieu de `.then()` chaîné après `.catch()`** — le pattern actuel est subtil. `.finally()` est plus lisible et supporté par tous les navigateurs modernes. (`static/kill-the-news.js:59-62`)

## 🟢 Qualité / Améliorations mineures

- [ ] **Couleur d'erreur hardcodée dans le CSS** — remplacer `color: #b00020` par `var(--error-color, #b00020)` pour suivre le thème FreshRSS. (`static/kill-the-news.css:38`)
- [ ] **Pas d'avertissement sur la désactivation TLS** — ajouter un texte d'aide sous la checkbox (comme `instance_url_help`) pour signaler le risque. (`configure.phtml:34-38`)
- [x] **`/api/v1/feeds` dupliqué** — extraire en `private const FEEDS_PATH = '/api/v1/feeds'` pour éviter la duplication et signaler le couplage à la version d'API. (`KillTheNewsClient.php:116, 134`)
- [x] **`parseFeedList` incohérent sur les entrées malformées** — la vérification `is_array($raw)` ignore les entrées non-array silencieusement, mais un array avec champs manquants lève une exception. Choisir une politique uniforme : soit tout ignorer (try/catch dans la boucle), soit ne rien ignorer. (`KillTheNewsClient.php:96-99`)
- [x] **Tests manquants** :
  - Transport qui échoue (curl error → exception) — `KillTheNewsClient.php:52-53`
  - `parseFeed` quand `rssUrl` est absent — `KillTheNewsClient.php:158`
  - `parseFeedList` avec entrée array mais champs manquants — `KillTheNewsClient.php:96`
  - `normalizeBaseUrl` avec URL `ftp://` ou `javascript://` — `KillTheNewsClient.php:60`
  - `createFeed` avec titre vide — `KillTheNewsClient.php:115`
- [ ] **`composer.json` incomplet** — ajouter `homepage`, PHPStan/Psalm en `require-dev`, et le script `analyse`. (`composer.json`)
- [ ] **Pas de CI** — ajouter un GitHub Actions minimal (`.github/workflows/ci.yml`) qui lance `composer test` sur push/PR.
- [ ] **README ne précise pas que la catégorie est hardcodée en anglais** — mentionner la limitation dans la section "Use". (`README.md:33`)
- [ ] **Pas de `CHANGELOG.md`** — créer dès la v0.1.0 pour faciliter le suivi des releases.
