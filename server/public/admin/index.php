<?php

declare(strict_types=1);

use Matsvep\Admin\AdminAuth;
use Matsvep\Admin\AdminIpAllowlist;
use Matsvep\Admin\AdminService;
use Matsvep\Ingest\Ingredient\DatabaseConnection;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function h(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (!is_scalar($value)) {
        throw new RuntimeException('Invalid display value.');
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safeSourceUrl(mixed $url): string
{
    return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false
        ? $url : '#';
}

function field(string $name, mixed $value = '', string $type = 'text'): string
{
    return '<label>' . h(str_replace('_', ' ', $name)) . ' <input type="' . h($type) . '" name="' . h($name) .
        '" value="' . h($value) . '"></label>';
}

function hidden(string $name, mixed $value): string
{
    return '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
}

function actionForm(string $action, string $csrf, string $contents): string
{
    return '<form method="post">' . hidden('action', $action) . hidden('csrf', $csrf) . $contents . '</form>';
}

function stringInput(mixed $value): string
{
    if (!is_string($value)) {
        throw new RuntimeException('Invalid form value.');
    }
    return $value;
}

function idFrom(mixed $value): int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        throw new RuntimeException('Invalid identifier.');
    }
    return (int) $value;
}

$server = dirname(__DIR__, 2);
$remote = $_SERVER['REMOTE_ADDR'] ?? null;
if (!is_string($remote) || !(new AdminIpAllowlist())->allowsFile(
    $server . '/config/admin-allowlist.conf',
    $remote
)) {
    http_response_code(403);
    exit('Admin access denied.');
}
if (($_SERVER['HTTPS'] ?? '') !== 'on') {
    http_response_code(403);
    exit('HTTPS required.');
}
$db = DatabaseConnection::fromEnvironment($server);
$auth = new AdminAuth($db);
$auth->startSession();
$service = new AdminService($db, $server . '/seeds');
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $auth->requireCsrf(stringInput($_POST['csrf'] ?? ''));
        $action = stringInput($_POST['action'] ?? '');
        if ($action === 'login') {
            if (!$auth->login(stringInput($_POST['email'] ?? ''), stringInput($_POST['password'] ?? ''))) {
                throw new RuntimeException('Invalid credentials or account temporarily locked.');
            }
        } else {
            $actor = $auth->userId();
            if ($actor === null) {
                throw new RuntimeException('Your admin session has expired.');
            }
            switch ($action) {
                case 'logout':
                    $auth->logout();
                    break;
                case 'unknown_map':
                    $service->resolveUnknown($actor, idFrom($_POST['id'] ?? null), 'map', idFrom($_POST['ingredient_id'] ?? null));
                    break;
                case 'unknown_create':
                    $service->resolveUnknown(
                        $actor,
                        idFrom($_POST['id'] ?? null),
                        'create',
                        null,
                        stringInput($_POST['name'] ?? ''),
                        stringInput($_POST['category'] ?? '')
                    );
                    break;
                case 'unknown_staple':
                case 'unknown_ignore':
                    $service->resolveUnknown($actor, idFrom($_POST['id'] ?? null), substr($action, 8));
                    break;
                case 'duplicate_merge':
                case 'duplicate_not_duplicate':
                    $service->reviewDuplicate(
                        $actor,
                        idFrom($_POST['a'] ?? null),
                        idFrom($_POST['b'] ?? null),
                        substr($action, 10),
                        $action === 'duplicate_merge' ? idFrom($_POST['keep'] ?? null) : null
                    );
                    break;
                case 'feature_save':
                    $values = [];
                    foreach (['main_protein', 'carb_base', 'cuisine', 'meal_type', 'is_dinner_candidate', 'leftover_score'] as $key) {
                        $values[$key] = stringInput($_POST[$key] ?? '');
                    }
                    $service->setFeatureOverride($actor, idFrom($_POST['id'] ?? null), $values);
                    break;
                case 'group_split':
                    $service->splitGroup($actor, idFrom($_POST['group_id'] ?? null), idFrom($_POST['recipe_id'] ?? null));
                    break;
                case 'group_merge':
                    $service->mergeGroups($actor, idFrom($_POST['source_id'] ?? null), idFrom($_POST['target_id'] ?? null));
                    break;
                default:
                    throw new RuntimeException('Unknown admin action.');
            }
        }
        header('Location: /admin/?page=' . rawurlencode(stringInput($_POST['return_page'] ?? 'overview')), true, 303);
        exit;
    } catch (Throwable $exception) {
        http_response_code(400);
        $error = $exception->getMessage();
    }
}
$actor = $auth->userId();
$csrf = $auth->csrfToken();
$page = stringInput($_GET['page'] ?? 'overview');
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>Matsvep admin</title><style>body{font:16px system-ui;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#222}';
echo 'nav a{margin-right:1rem}form{display:inline-block;margin:.4rem}label{display:inline-block;margin:.25rem}';
echo 'input,select,button{font:inherit;padding:.35rem}table{border-collapse:collapse;width:100%}';
echo 'td,th{border-bottom:1px solid #ccc;padding:.6rem;text-align:left;vertical-align:top}';
echo '.pair{display:grid;grid-template-columns:1fr 1fr;gap:1rem}.card{border:1px solid #ccc;padding:1rem;margin:1rem 0}';
echo '.error{background:#fee;padding:1rem}small{color:#555}</style><body><h1>Matsvep admin</h1>';
if ($error !== null) {
    echo '<p class="error">' . h($error) . '</p>';
}
if ($actor === null) {
    echo actionForm('login', $csrf, field('email') . field('password', '', 'password') . '<button>Log in</button>');
    echo '</body></html>';
    exit;
}
echo '<nav><a href="/admin/">Overview</a><a href="?page=unknowns">Unknown ingredients</a>';
echo '<a href="?page=duplicates">Duplicates</a><a href="?page=recipes">Recipes</a>';
echo '<a href="?page=groups">Dish groups</a>' . actionForm('logout', $csrf, '<button>Log out</button>') . '</nav>';
try {
    switch ($page) {
        case 'overview':
            echo '<h2>Overview</h2><dl>';
            foreach ($service->overview() as $key => $value) {
                echo '<dt>' . h(str_replace('_', ' ', $key)) . '</dt><dd>' . h($value) . '</dd>';
            }
            echo '</dl>';
            break;
        case 'unknowns':
            echo '<h2>Unknown ingredients</h2>';
            $ingredients = $service->ingredients();
            echo '<datalist id="ingredient-options">';
            foreach ($ingredients as $ingredient) {
                echo '<option value="' . h($ingredient['id']) . '" label="' . h($ingredient['canonical_name']) . '">';
            }
            echo '</datalist>';
            foreach ($service->unknowns() as $row) {
                echo '<div class="card"><strong>' . h($row['normalised_token']) . '</strong> — ' . h($row['occurrences']) . ' occurrences';
                echo '<p>Example: ' . h($row['raw_text']) . '<br>Suggestion: ' . h($row['suggestion'] ?? 'none') . '</p>';
                echo actionForm('unknown_map', $csrf, hidden('id', $row['id']) . hidden('return_page', 'unknowns') .
                    '<label>Ingredient ID <input name="ingredient_id" type="number" min="1" list="ingredient-options" ' .
                    'value="' . h($row['suggested_ingredient_id'] ?? '') . '"></label><button>Map</button>');
                echo actionForm('unknown_create', $csrf, hidden('id', $row['id']) . hidden('return_page', 'unknowns') .
                    field('name', $row['normalised_token']) . field('category', 'pantry') . '<button>Create ingredient</button>');
                echo actionForm('unknown_staple', $csrf, hidden('id', $row['id']) . hidden('return_page', 'unknowns') . '<button>Mark staple</button>');
                echo actionForm('unknown_ignore', $csrf, hidden('id', $row['id']) . hidden('return_page', 'unknowns') . '<button>Ignore</button>');
                echo '</div>';
            }
            break;
        case 'duplicates':
            echo '<h2>Duplicate review</h2>';
            foreach ($service->duplicates() as $row) {
                echo '<div class="card"><p>Similarity: ' . h($row['similarity']) . '</p><div class="pair">';
                foreach (['a', 'b'] as $side) {
                    $id = idFrom($row['recipe_' . $side]);
                    echo '<section><h3>' . h($row['title_' . $side]) . '</h3><p><a href="' . h(safeSourceUrl($row['url_' . $side])) . '">Source</a></p><ul>';
                    foreach ($service->recipeLines($id) as $line) {
                        echo '<li>' . h($line['raw_text']) . '</li>';
                    }
                    echo '</ul><ol>';
                    foreach ($service->recipeSteps($id) as $step) {
                        echo '<li>' . h($step['text']) . '</li>';
                    }
                    echo '</ol></section>';
                }
                echo '</div>';
                echo actionForm('duplicate_merge', $csrf, hidden('a', $row['recipe_a']) . hidden('b', $row['recipe_b']) .
                    hidden('return_page', 'duplicates') . '<label>Keep <select name="keep"><option value="' . h($row['recipe_a']) . '">A</option>' .
                    '<option value="' . h($row['recipe_b']) . '">B</option></select></label><button>Merge</button>');
                echo actionForm('duplicate_not_duplicate', $csrf, hidden('a', $row['recipe_a']) .
                    hidden('b', $row['recipe_b']) . hidden('return_page', 'duplicates') . '<button>Not a duplicate</button>');
                echo '</div>';
            }
            break;
        case 'recipes':
            $query = stringInput($_GET['q'] ?? '');
            echo '<h2>Recipes</h2><form method="get">' . hidden('page', 'recipes') . field('q', $query) . '<button>Search</button></form>';
            foreach ($service->recipes($query) as $recipe) {
                echo '<div class="card"><strong>' . h($recipe['title']) . '</strong> <a href="' . h(safeSourceUrl($recipe['url'])) . '">Source</a>';
                echo '<p>Current: protein ' . h($recipe['main_protein']) . ', carb ' . h($recipe['carb_base']) .
                    ', cuisine ' . h($recipe['cuisine']) . ', meal type ' . h($recipe['meal_type']) .
                    ', dinner ' . h($recipe['is_dinner_candidate']) . ', leftover ' . h($recipe['leftover_score']) . '</p>';
                $override = $service->override(idFrom($recipe['id'])) ?? [];
                $form = hidden('id', $recipe['id']) . hidden('return_page', 'recipes');
                foreach (['main_protein', 'carb_base', 'cuisine', 'meal_type', 'leftover_score'] as $fieldName) {
                    $form .= field($fieldName, $override[$fieldName] ?? '');
                }
                $value = $override['is_dinner_candidate'] ?? '';
                $form .= '<label>Dinner candidate <select name="is_dinner_candidate"><option value="">Automatic</option>' .
                    '<option value="1"' . ($value === '1' || $value === 1 ? ' selected' : '') . '>Yes</option>' .
                    '<option value="0"' . ($value === '0' || $value === 0 ? ' selected' : '') . '>No</option></select></label>';
                echo actionForm('feature_save', $csrf, $form . '<button>Save overrides</button>');
                echo '</div>';
            }
            break;
        case 'groups':
            echo '<h2>Dish groups</h2>';
            $groups = $service->groups();
            foreach ($groups as $group) {
                echo '<div class="card"><h3>' . h($group['core_name']) . ' (' . h($group['recipe_count']) . ')</h3><ul>';
                foreach ($service->groupRecipes(idFrom($group['id'])) as $recipe) {
                    echo '<li>' . h($recipe['title']) . ' ' . actionForm(
                        'group_split',
                        $csrf,
                        hidden('group_id', $group['id']) . hidden('recipe_id', $recipe['id']) .
                        hidden('return_page', 'groups') . '<button>Split into new group</button>'
                    ) . '</li>';
                }
                echo '</ul>';
                $options = '';
                foreach ($groups as $target) {
                    if ($target['id'] !== $group['id']) {
                        $options .= '<option value="' . h($target['id']) . '">' . h($target['core_name']) . '</option>';
                    }
                }
                if ($options !== '') {
                    echo actionForm('group_merge', $csrf, hidden('source_id', $group['id']) .
                        hidden('return_page', 'groups') . '<select name="target_id">' . $options . '</select><button>Merge into group</button>');
                }
                echo '</div>';
            }
            break;
        default:
            http_response_code(404);
            echo '<p>Page not found.</p>';
    }
} catch (Throwable $exception) {
    http_response_code(500);
    echo '<p class="error">' . h($exception->getMessage()) . '</p>';
}
echo '</body></html>';
