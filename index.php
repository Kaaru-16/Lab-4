<?php
session_start();
date_default_timezone_set('Asia/Taipei');
$minimumDue = date('Y-m-d\TH:i');

function escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function iconPath($name) {
    return 'image/' . $_SESSION['theme'] . '/icon-' . $name . '.svg';
}

function posted($key, $default = '') {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function textLength($value) {
    $length = preg_match_all('/./us', $value);
    return $length === false ? PHP_INT_MAX : $length;
}

function buildViewLink($view, $params) {
    if ($view === 'all') unset($params['view']);
    else $params['view'] = $view;
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

function taskId(string $value): ?int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    return $id === false ? null : $id;
}

function findTask(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT task_name AS name, task_description AS description,
                DATE_FORMAT(due_at, '%Y-%m-%dT%H:%i') AS due, priority, category, status, reminder
         FROM tasks WHERE id = ?"
    );
    $stmt->execute([$id]);
    $task = $stmt->fetch();
    if ($task === false) {
        return null;
    }

    $task['due'] = $task['due'] ?? '';
    $task['status'] = $task['status'] === 'completed' ? 'completed' : 'todo';
    return $task;
}

$_SESSION['collapsed'] = $_SESSION['collapsed'] ?? false;
$_SESSION['theme'] = ($_SESSION['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
$token = $_SESSION['task_db_token'] ??= bin2hex(random_bytes(32));
$pageParams = array_filter($_GET, 'is_string');
$pageUrl = 'index.php' . ($pageParams ? '?' . http_build_query($pageParams) : '');
$errors = [];
$showForm = isset($pageParams['new']);
$showCategoryForm = isset($pageParams['new_category']);
$showCategoryDelete = false;
$deleteCategoryName = '';
$categoryName = '';
$editing = false;
$taskId = null;
$savedDue = '';
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);

try {
    require __DIR__ . '/db.php';

    $stmt = $pdo->prepare('SELECT name FROM categories ORDER BY id');
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $requestedCategory = $pageParams['category'] ?? '';
    $selectedCategory = in_array($requestedCategory, $categories, true) ? $requestedCategory : null;
    $defaultCategory = in_array('Coursework', $categories, true) ? 'Coursework' : ($categories[0] ?? '');
    $fields = [
        'name' => '', 'description' => '', 'due' => '', 'priority' => 'medium',
        'category' => $selectedCategory ?? $defaultCategory, 'status' => 'todo', 'reminder' => '',
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = posted('action');
        $taskId = taskId(posted('task_id'));

        if (!hash_equals($token, posted('token'))) {
            http_response_code(403);
            $errors[] = 'Your form expired. Reload the page and try again.';
        } elseif ($action === 'menu') {
            $_SESSION['collapsed'] = !$_SESSION['collapsed'];
        } elseif ($action === 'theme') {
            $_SESSION['theme'] = $_SESSION['theme'] === 'dark' ? 'light' : 'dark';
        } elseif ($action === 'toggle_complete' || $action === 'delete') {
            if ($taskId === null) {
                http_response_code(404);
                $errors[] = 'Task not found.';
            } else {
                $stmt = $pdo->prepare($action === 'delete'
                    ? 'DELETE FROM tasks WHERE id = ?'
                    : "UPDATE tasks SET status = IF(status = 'completed', 'pending', 'completed') WHERE id = ?");
                $stmt->execute([$taskId]);
                if ($stmt->rowCount() === 0) {
                    http_response_code(404);
                    $errors[] = 'Task not found.';
                } else {
                    $_SESSION['notice'] = $action === 'delete' ? 'Task deleted.' : 'Task status updated.';
                }
            }
        } elseif ($action === 'category_create') {
            $showCategoryForm = true;
            $categoryName = posted('category_name');
            if ($categoryName === '' || textLength($categoryName) > 30) {
                $errors[] = 'Category name must contain 1 to 30 characters.';
            } elseif (in_array(strtolower($categoryName), array_map('strtolower', $categories), true)) {
                $errors[] = 'That category already exists.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO categories (name) VALUES (?) ON DUPLICATE KEY UPDATE id = id');
                $stmt->execute([$categoryName]);
                if ($stmt->rowCount() === 0) {
                    $errors[] = 'That category already exists.';
                } else {
                    $_SESSION['notice'] = 'Category created.';
                }
            }
        } elseif ($action === 'category_delete') {
            $categoryName = posted('category_name');
            $deleted = false;
            if (in_array($categoryName, $categories, true)) {
                $stmt = $pdo->prepare('DELETE FROM categories WHERE name = ?');
                $stmt->execute([$categoryName]);
                $deleted = $stmt->rowCount() > 0;
            }
            if ($deleted) {
                $_SESSION['notice'] = 'Category and its tasks deleted.';
            } else {
                http_response_code(404);
                $errors[] = 'Category not found.';
            }
        } elseif ($action === 'create' || $action === 'update') {
            $editing = $action === 'update';
            $showForm = true;
            if ($editing) {
                $savedTask = $taskId === null ? null : findTask($pdo, $taskId);
                if ($savedTask === null) {
                    http_response_code(404);
                    $errors[] = 'Task not found.';
                } else {
                    $savedDue = $savedTask['due'];
                }
            }

            foreach ($fields as $key => $default) {
                $fields[$key] = posted($key, $default);
            }
            $fields['reminder'] = $fields['reminder'] === '1' ? 1 : 0;
            if (textLength($fields['name']) < 3 || textLength($fields['name']) > 100) {
                $errors[] = 'Task name must contain 3 to 100 characters.';
            }
            if ($fields['description'] === '' || textLength($fields['description']) > 255) {
                $errors[] = 'Description must contain 1 to 255 characters.';
            }
            if (!in_array($fields['priority'], ['high', 'medium', 'low'], true)) {
                $errors[] = 'Choose a valid priority.';
            }
            if (!in_array($fields['category'], $categories, true)) {
                $errors[] = 'Choose a valid category.';
            }
            if (!in_array($fields['status'], ['todo', 'completed'], true)) {
                $errors[] = 'Choose a valid status.';
            }

            $dueAt = null;
            if ($fields['due'] !== '') {
                $date = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $fields['due'])
                    ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $fields['due'])
                    : false;
                if (!$date || $date->format('Y-m-d\TH:i') !== $fields['due'] || (int) $date->format('Y') < 1000) {
                    $errors[] = 'Enter a valid due date and time.';
                } elseif ($fields['due'] < $minimumDue && $fields['due'] !== $savedDue) {
                    $errors[] = 'Choose a current or future due date and time.';
                } else {
                    $dueAt = $date->format('Y-m-d H:i:s');
                }
            }

            if (!$errors) {
                $status = $fields['status'] === 'completed' ? 'completed' : 'pending';
                $values = [
                    $fields['name'], $fields['description'], $dueAt, $fields['priority'],
                    $fields['category'], $status, $fields['reminder'],
                ];
                try {
                    if ($editing) {
                        $stmt = $pdo->prepare(
                            'UPDATE tasks SET task_name = ?, task_description = ?, due_at = ?, priority = ?,
                                              category = ?, status = ?, reminder = ? WHERE id = ?'
                        );
                        $stmt->execute([...$values, $taskId]);
                        if ($stmt->rowCount() === 0 && findTask($pdo, $taskId) === null) {
                            http_response_code(404);
                            $errors[] = 'Task not found.';
                        } else {
                            $_SESSION['notice'] = 'Task updated.';
                        }
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO tasks (task_name, task_description, due_at, priority, category, status, reminder)
                             VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->execute($values);
                        $_SESSION['notice'] = 'Task created.';
                    }
                } catch (PDOException $error) {
                    if ((int) ($error->errorInfo[1] ?? 0) !== 1452) {
                        throw $error;
                    }
                    http_response_code(409);
                    $errors[] = 'This category is no longer available. Reload the board to choose another.';
                }
            }
        } else {
            http_response_code(400);
            $errors[] = 'Unknown form action.';
        }

        if (!$errors) {
            if ($action === 'menu' && ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                header('Content-Type: application/json');
                echo json_encode(['collapsed' => $_SESSION['collapsed']]);
                exit;
            }
            header('Location: ' . (in_array($action, ['menu', 'theme'], true) ? $pageUrl : 'index.php'), true, 303);
            exit;
        }
        if (http_response_code() === 200) {
            http_response_code(422);
        }
    } else {
        if (isset($_GET['delete_category'])) {
            $deleteCategoryName = $pageParams['delete_category'] ?? '';
            $showCategoryDelete = in_array($deleteCategoryName, $categories, true);
            if (!$showCategoryDelete) {
                http_response_code(404);
                $errors[] = 'Category not found.';
            }
        }
        if (isset($_GET['edit'])) {
            $taskId = taskId($pageParams['edit'] ?? '');
            $task = $taskId === null ? null : findTask($pdo, $taskId);
            if ($task === null) {
                http_response_code(404);
                $errors[] = 'Task not found.';
            } else {
                $fields = $task;
                $savedDue = $fields['due'];
                $showForm = true;
                $editing = true;
            }
        }
    }

    $stmt = $pdo->prepare(
        'SELECT id, task_name AS name, task_description AS description, due_at, priority, category, status
         FROM tasks ORDER BY id'
    );
    $stmt->execute();
    $tasks = $stmt->fetchAll();
} catch (PDOException $error) {
    error_log('Block Tasks database error: ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Database unavailable. Check the connection settings and import task_db.sql.');
}

$columns = ['todo' => 'To do', 'overdue' => 'Overdue', 'completed' => 'Completed'];
$counts = array_fill_keys(array_keys($columns), 0);
$tasksByStatus = array_fill_keys(array_keys($columns), []);
$searchQuery = trim($pageParams['search'] ?? '');
$priorityFilter = strtolower($pageParams['priority'] ?? '');
$dueStatusFilter = strtolower($pageParams['due_status'] ?? '');
$viewFilter = $pageParams['view'] ?? 'all';
$now = time();

foreach ($tasks as $task) {
    $due = $task['due_at'] === null ? null : strtotime($task['due_at']);
    $status = $task['status'] === 'completed' ? 'completed' : ($due !== null && $due < $now ? 'overdue' : 'todo');

    if ($selectedCategory !== null && $task['category'] !== $selectedCategory) {
        continue;
    }
    if ($searchQuery !== '' && stripos($task['name'], $searchQuery) === false && stripos($task['description'], $searchQuery) === false) {
        continue;
    }
    if ($priorityFilter !== '' && $priorityFilter !== $task['priority']) {
        continue;
    }
    if ($dueStatusFilter !== '' && $dueStatusFilter !== $status) {
        continue;
    }

    $counts[$status]++;
    if (($viewFilter === 'active' && $status === 'completed') || ($viewFilter === 'completed' && $status !== 'completed')) {
        continue;
    }
    $task['due'] = $due === null ? 'No due date' : date('M j, Y, g:i A', $due);
    $tasksByStatus[$status][] = $task;
}

$total = array_sum($counts);
$dueInputMinimum = $savedDue !== '' && $fields['due'] === $savedDue && $savedDue < $minimumDue ? $savedDue : $minimumDue;
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="color-scheme" content="<?= $_SESSION['theme'] ?>">
        <title>Block Tasks | Task inventory</title>
        <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
        <script src="script.js?v=<?= filemtime(__DIR__ . '/script.js') ?>" defer></script>
    </head>
    <body class="<?= $_SESSION['collapsed'] ? 'sidebar-collapsed' : '' ?> <?= $_SESSION['theme'] === 'light' ? 'light-theme' : '' ?>">
        <aside class="sidebar" aria-label="Workspace sidebar">
            <form action="<?= escape($pageUrl) ?>" method="POST" class="control-form">
                <input type="hidden" name="token" value="<?= escape($token) ?>">
                <button type="submit" class="menu-button" name="action" value="menu" aria-label="Toggle sidebar" aria-expanded="<?= $_SESSION['collapsed'] ? 'false' : 'true' ?>">
                    <img src="<?= iconPath('menu') ?>" alt="">
                </button>
            </form>
            <header class="brand" aria-label="Block Tasks">
                <span>BLOCK</span>
                <span>TASKS</span>
                <small>YOUR DAILY TASK INVENTORY</small>
            </header>
            <figure class="profile">
                <div class="avatar">
                    <div class="avatar-crop">
                        <img src="image/dark/image1.png" alt="John Carl Lasala's avatar">
                    </div>
                </div>
                <figcaption>
                    <p>John Carl Lasala</p>
                    <small>Personal workspace</small>
                </figcaption>
            </figure>
            <hr>
            <h2 class="nav-label">TASK INVENTORY</h2>
            <nav class="navigation" aria-label="Task views">
                <a href="index.php" class="<?= $selectedCategory === null ? 'selected' : '' ?>" <?= $selectedCategory === null ? 'aria-current="page"' : '' ?>>
                    <img src="<?= iconPath('grid') ?>" alt="">
                    <span>My tasks</span>
                </a>
            </nav>
            <h2 class="category-label">CATEGORIES</h2>
            <ul class="categories">
                <?php foreach ($categories as $category): ?>
                    <li>
                        <a href="index.php?category=<?= rawurlencode($category) ?>" class="<?= $selectedCategory === $category ? 'selected' : '' ?>" <?= $selectedCategory === $category ? 'aria-current="page"' : '' ?>>
                            <span class="category-marker <?= in_array($category, ['Coursework', 'Study', 'Personal'], true) ? strtolower($category) : '' ?>"></span>
                            <?= escape($category) ?>
                        </a>
                        <a href="index.php?delete_category=<?= rawurlencode($category) ?>" class="category-delete" aria-label="Delete <?= escape($category) ?> category" title="Delete category">
                            <img src="<?= iconPath('trash') ?>" alt="">
                        </a>
                    </li>
                <?php endforeach; ?>
                <li><a href="index.php?new_category=1" class="text-button">
                    <img src="<?= iconPath($_SESSION['theme'] === 'light' ? 'plus2' : 'plus1') ?>" alt="">
                    <span>New category</span>
                </a></li>
            </ul>
            <div class="appearance">
                <h2>APPEARANCE</h2>
                <form action="<?= escape($pageUrl) ?>" method="POST" class="control-form switch-wrap">
                    <input type="hidden" name="token" value="<?= escape($token) ?>">
                    <button class="appearance-switch" name="action" value="theme" aria-label="<?= $_SESSION['theme'] === 'dark' ? 'Use light theme' : 'Use dark theme' ?>" aria-pressed="<?= $_SESSION['theme'] === 'light' ? 'true' : 'false' ?>"><span></span></button>
                </form>
            </div>
        </aside>
        <main class="workspace">
            <header class="page-header">
                <div class="heading">
                    <h1>Task inventory</h1>
                    <time datetime="<?= date('Y-m-d') ?>"><?= date('l, j F Y') ?></time>
                </div>
                <form action="index.php" method="GET" class="search">
                    <img src="<?= iconPath('search') ?>" alt="">
                    <input type="text" name="search" value="<?= escape($searchQuery) ?>" placeholder="Search task names or descriptions" aria-label="Search tasks">
                </form>
                <div class="header-rule"></div>
                <button type="button" class="notification-button" aria-label="Notifications">
                    <img src="<?= iconPath('bell') ?>" alt="">
                </button>
                <a href="<?= $showForm ? 'index.php' : 'index.php?new=1' ?>" class="primary-button new-task" aria-expanded="<?= $showForm ? 'true' : 'false' ?>">
                    <img src="<?= iconPath('plus') ?>" alt=""><span>New task</span>
                </a>
            </header>
            <?php if ($notice !== ''): ?><p class="notice" role="status"><?= escape($notice) ?></p><?php endif; ?>
            <?php if ($errors && !$showForm && !$showCategoryForm && !$showCategoryDelete): ?><p class="errors" role="alert"><?= escape(implode(' ', $errors)) ?></p><?php endif; ?>
            <section class="summary" aria-label="Task summary">
                <header>
                    <h2>Your next task starts here.</h2>
                    <p><?= $total ?> <?= $total === 1 ? 'task' : 'tasks' ?> on this board.</p>
                </header>
                <?php foreach ($columns as $status => $label): ?>
                    <div class="stat <?= $status ?>-stat">
                        <strong><?= str_pad((string) $counts[$status], 2, '0', STR_PAD_LEFT) ?></strong>
                        <span><?= $label ?></span>
                    </div>
                <?php endforeach; ?>
            </section>
            <div class="toolbar" aria-label="Task filters">
                <div class="view-filters">
                    <a href="<?= escape(buildViewLink('all', $pageParams)) ?>" class="view-filter-btn <?= $viewFilter === 'all' ? 'selected' : '' ?>">All tasks · <?= $total ?></a>
                    <a href="<?= escape(buildViewLink('active', $pageParams)) ?>" class="view-filter-btn <?= $viewFilter === 'active' ? 'selected' : '' ?>">Active · <?= $total - $counts['completed'] ?></a>
                    <a href="<?= escape(buildViewLink('completed', $pageParams)) ?>" class="view-filter-btn <?= $viewFilter === 'completed' ? 'selected' : '' ?>">Completed · <?= $counts['completed'] ?></a>
                </div>
                <div class="toolbar-rule"></div>
                <form action="index.php" method="GET" class="sort-filters">
                    <?php if ($selectedCategory !== null): ?>
                        <input type="hidden" name="category" value="<?= escape($selectedCategory) ?>">
                    <?php endif; ?>
                    <?php if ($viewFilter !== 'all'): ?>
                        <input type="hidden" name="view" value="<?= escape($viewFilter) ?>">
                    <?php endif; ?>
                    <?php if ($searchQuery !== ''): ?>
                        <input type="hidden" name="search" value="<?= escape($searchQuery) ?>">
                    <?php endif; ?>
                    
                    <label class="select-filter">
                        <img src="<?= iconPath('filter') ?>" alt="">
                        <select aria-label="Priority filter" name="priority">
                            <option value="">All priorities</option>
                            <option value="high" <?= ($priorityFilter === 'high') ? 'selected' : '' ?>>High priority</option>
                            <option value="medium" <?= ($priorityFilter === 'medium') ? 'selected' : '' ?>>Medium priority</option>
                            <option value="low" <?= ($priorityFilter === 'low') ? 'selected' : '' ?>>Low priority</option>
                        </select>
                        <img src="<?= iconPath('chevron') ?>" alt="">
                    </label>

                    <label class="select-filter">
                        <select aria-label="Due date filter" name="due_status">
                            <option value="">Due date</option>
                            <option value="todo" <?= ($dueStatusFilter === 'todo') ? 'selected' : '' ?>>To do</option>
                            <option value="overdue" <?= ($dueStatusFilter === 'overdue') ? 'selected' : '' ?>>Overdue</option>
                            <option value="completed" <?= ($dueStatusFilter === 'completed') ? 'selected' : '' ?>>Completed</option>
                        </select>
                        <img src="<?= iconPath('chevron') ?>" alt="">
                    </label>
                </form>
            </div>
            <section class="task-board" aria-label="Task inventory board">
                <?php foreach ($columns as $status => $label): ?>
                    <section class="task-column <?= $status ?>" aria-labelledby="column-<?= $status ?>">
                        <header class="column-heading">
                            <span class="status-marker" aria-hidden="true"></span>
                            <h2 id="column-<?= $status ?>"><?= $label ?></h2>
                            <span class="task-count"><?= $counts[$status] ?></span>
                        </header>
                        <?php foreach ($tasksByStatus[$status] as $task): ?>
                            <article class="task-card <?= $status === 'completed' ? 'is-complete' : '' ?>">
                                <header class="card-meta">
                                    <span class="priority <?= $status === 'completed' ? 'low' : escape($task['priority']) ?>"><?= $status === 'completed' ? 'Completed' : ucfirst(escape($task['priority'])) . ' priority' ?></span>
                                    <span><?= escape($task['category']) ?></span>
                                </header>
                                <header class="task-heading">
                                    <form id="toggle-form-<?= (int) $task['id'] ?>" action="index.php" method="POST" class="completion-form">
                                        <input type="hidden" name="token" value="<?= escape($token) ?>">
                                        <input type="hidden" name="action" value="toggle_complete">
                                        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                        <label class="task-checkbox">
                                            <input type="checkbox" aria-label="Toggle <?= escape($task['name']) ?> completion" <?= $status === 'completed' ? 'checked' : '' ?>>
                                            <?php if ($status === 'completed'): ?><img src="<?= iconPath('check') ?>" alt=""><?php endif; ?>
                                        </label>
                                    </form>
                                    <h3><?= escape($task['name']) ?></h3>
                                </header>
                                <p><?= escape($task['description']) ?></p>
                                <footer class="card-footer">
                                    <img src="<?= iconPath('clock') ?>" alt="">
                                    <span><?= escape($task['due']) ?></span>
                                    <div class="card-actions">
                                        <a href="index.php?edit=<?= (int) $task['id'] ?>" aria-label="Edit <?= escape($task['name']) ?>"><img src="<?= iconPath('edit') ?>" alt=""></a>
                                        <form id="delete-form-<?= (int) $task['id'] ?>" action="index.php" method="POST" class="control-form">
                                            <input type="hidden" name="token" value="<?= escape($token) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                            <button type="button" data-delete-task="<?= (int) $task['id'] ?>" aria-label="Delete <?= escape($task['name']) ?>"><img src="<?= iconPath('trash') ?>" alt=""></button>
                                        </form>
                                    </div>
                                </footer>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($status === 'todo'): ?>
                            <a href="index.php?new=1" class="text-button add-task"><img src="<?= iconPath('plus1') ?>" alt="">Add task</a>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </section>
        </main>
        <?php if ($showForm || $showCategoryForm || $showCategoryDelete): ?>
            <aside id="create-task" class="<?= $showCategoryDelete || $showCategoryForm ? 'modal-overlay category-overlay' : 'task-panel' ?>" aria-labelledby="create-title">
                <section class="<?= $showCategoryDelete || $showCategoryForm ? 'modal-panel category-dialog' . ($showCategoryDelete ? ' category-delete-dialog' : ' category-create-dialog') : 'create-panel' ?>" <?= $showCategoryDelete || $showCategoryForm ? 'role="dialog" aria-modal="true" aria-labelledby="create-title"' : '' ?>>
                    <header class="form-heading">
                        <h2 id="create-title"><?= $showCategoryDelete ? 'Delete Category' : ($showCategoryForm ? 'Create Category' : ($editing ? 'Edit task' : 'Create task')) ?></h2>
                        <?php if (!$showCategoryForm && !$showCategoryDelete): ?><a href="index.php" class="secondary-button">Close</a><?php endif; ?>
                    </header>
                    <?php if ($showCategoryDelete): ?>
                        <p>Delete &quot;<?= escape($deleteCategoryName) ?>&quot; and all tasks in this category? This cannot be undone.</p>
                    <?php elseif (!$showCategoryForm): ?>
                        <p>Add details to plan your next step.</p>
                    <?php endif; ?>
                    <?php if ($errors): ?>
                        <ul class="errors" role="alert">
                            <?php foreach ($errors as $error): ?><li><?= escape($error) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($showCategoryDelete): ?>
                        <form action="index.php" method="POST" class="create-form category-form">
                            <input type="hidden" name="token" value="<?= escape($token) ?>">
                            <input type="hidden" name="action" value="category_delete">
                            <input type="hidden" name="category_name" value="<?= escape($deleteCategoryName) ?>">
                            <footer class="form-actions">
                                <button class="primary-button delete-button">Delete Category</button>
                                <a href="index.php" class="secondary-button">Cancel</a>
                            </footer>
                        </form>
                    <?php elseif ($showCategoryForm): ?>
                        <form action="index.php" method="POST" class="create-form category-form">
                            <input type="hidden" name="token" value="<?= escape($token) ?>">
                            <input type="hidden" name="action" value="category_create">
                            <label for="category-name">Category name</label>
                            <input id="category-name" name="category_name" value="<?= escape($categoryName) ?>" placeholder="Enter a category name" required maxlength="30">
                            <footer class="form-actions">
                                <button class="primary-button"><img src="image/dark/icon-category-plus.svg" alt="">Create Category</button>
                                <a href="index.php" class="secondary-button">Cancel</a>
                            </footer>
                        </form>
                    <?php else: ?>
                        <form action="index.php" method="POST" class="create-form">
                            <input type="hidden" name="token" value="<?= escape($token) ?>">
                            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                            <?php if ($editing && $taskId !== null): ?>
                                <input type="hidden" name="task_id" value="<?= (int) $taskId ?>">
                            <?php endif; ?>
                            <label for="task-name">Task name *</label>
                            <input id="task-name" name="name" value="<?= escape($fields['name']) ?>" placeholder="Enter a task name" aria-describedby="name-help" required minlength="3" maxlength="100" autofocus>
                            <small id="name-help">At least 3 characters.</small>
                            <label for="task-description">Description *</label>
                            <textarea id="task-description" name="description" aria-describedby="description-help" required maxlength="255" rows="3" placeholder="Describe what needs to get done."><?= escape($fields['description']) ?></textarea>
                            <small id="description-help"><?= textLength($fields['description']) ?> / 255 characters</small>
                            <label for="task-due">Due date &amp; time</label>
                            <input type="datetime-local" id="task-due" name="due" min="<?= escape($dueInputMinimum) ?>" data-saved-due="<?= escape($savedDue) ?>" value="<?= escape($fields['due']) ?>" aria-describedby="due-help">
                            <small id="due-help">Choose today or a future date and time.</small>
                            <fieldset class="priority-choices">
                                <legend>Priority</legend>
                                <?php foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $value => $label): ?>
                                    <label><input type="radio" name="priority" value="<?= $value ?>" <?= $fields['priority'] === $value ? 'checked' : '' ?>><?= $label ?></label>
                                <?php endforeach; ?>
                            </fieldset>
                            <label for="task-category">Category</label>
                            <select id="task-category" name="category" required>
                                <?php if (!$categories): ?><option value="">Create a category first</option><?php endif; ?>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= escape($category) ?>" <?= $fields['category'] === $category ? 'selected' : '' ?>><?= escape($category) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="task-status">Status</label>
                            <select id="task-status" name="status">
                                <option value="todo" <?= $fields['status'] === 'todo' ? 'selected' : '' ?>>To do</option>
                                <option value="completed" <?= $fields['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                            <label class="reminder"><input type="checkbox" name="reminder" value="1" <?= !empty($fields['reminder']) ? 'checked' : '' ?>>Remind me 15 minutes before</label>
                            <footer class="form-actions">
                                <button class="primary-button"><span class="form-icon"><img src="<?= $editing ? 'image/dark/icon-form-edit.svg' : 'image/dark/icon-form-plus.svg' ?>" alt=""></span><?= $editing ? 'Update task' : 'Create task' ?></button>
                                <button type="reset" class="secondary-button">Reset</button>
                            </footer>
                        </form>
                    <?php endif; ?>
                </section>
            </aside>
        <?php endif; ?>

        <div id="delete-modal-overlay" class="modal-overlay" hidden>
            <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="delete-title">
                <h2 id="delete-title">Delete</h2>
                <p>Are you sure you want to delete this task?</p>
                <div class="form-actions">
                    <button type="button" id="confirm-delete-btn" class="primary-button delete-button"><img src="image/dark/icon-delete.svg" alt="">Delete task</button>
                    <button type="button" id="cancel-delete-btn" class="secondary-button">Cancel</button>
                </div>
            </section>
        </div>

    </body>
</html>