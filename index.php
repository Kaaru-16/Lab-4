<?php
session_start();
date_default_timezone_set('Asia/Taipei');

function escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function iconPath($name) {
    return 'image/' . $_SESSION['theme'] . '/icon-' . $name . '.svg';
}

function inputText(array $input, string $key, string $default = ''): string {
    return isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : $default;
}

function textLength(string $value): int {
    $length = preg_match_all('/./us', $value);
    return $length === false ? PHP_INT_MAX : $length;
}

function taskId(string $value): ?int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    return $id === false ? null : $id;
}

function findTask(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        'SELECT task_name AS name, task_description AS description, due_at, priority, category, status
         FROM tasks WHERE id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function buildViewLink(string $view, array $filters): string {
    $filters['view'] = $view === 'all' ? '' : $view;
    $query = http_build_query(array_filter($filters, static fn ($value) => $value !== ''));
    return 'index.php' . ($query === '' ? '' : '?' . $query);
}

$_SESSION['collapsed'] = $_SESSION['collapsed'] ?? false;
$_SESSION['theme'] = ($_SESSION['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
$_SESSION['token'] = $_SESSION['token'] ?? bin2hex(random_bytes(32));
$fields = ['name' => '', 'description' => '', 'due' => '', 'priority' => 'medium', 'category' => 'Coursework', 'status' => 'pending'];
$errors = [];
$showForm = isset($_GET['new']);
$editing = false;
$taskId = null;
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);

try {
    require __DIR__ . '/db.php';

    $stmt = $pdo->prepare('SELECT name FROM categories ORDER BY id');
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = inputText($_POST, 'action');
        $taskId = taskId(inputText($_POST, 'task_id'));

        if (!hash_equals($_SESSION['token'], inputText($_POST, 'token'))) {
            http_response_code(403);
            $errors[] = 'Your form expired. Reload the page and try again.';
        } elseif ($action === 'menu') {
            $_SESSION['collapsed'] = !$_SESSION['collapsed'];
        } elseif ($action === 'theme') {
            $_SESSION['theme'] = $_SESSION['theme'] === 'dark' ? 'light' : 'dark';
        } elseif ($action === 'create_category') {
            $category = inputText($_POST, 'category_name');
            if ($category === '' || textLength($category) > 50) {
                $errors[] = 'Category name must contain 1 to 50 characters.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO categories (name) VALUES (?) ON DUPLICATE KEY UPDATE id = id');
                $stmt->execute([$category]);
                if ($stmt->rowCount() === 0) {
                    $errors[] = 'That category already exists.';
                } else {
                    $_SESSION['notice'] = 'Category created.';
                }
            }
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
        } elseif ($action === 'create' || $action === 'update') {
            $editing = $action === 'update';
            $showForm = true;
            foreach ($fields as $key => $default) {
                $fields[$key] = inputText($_POST, $key, $default);
            }

            if ($editing && $taskId === null) {
                http_response_code(404);
                $errors[] = 'Task not found.';
            }
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
            if (!in_array($fields['status'], ['pending', 'completed'], true)) {
                $errors[] = 'Choose a valid status.';
            }
            $dueAt = null;
            if ($fields['due'] !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $fields['due']);
                if (!$date || $date->format('Y-m-d\TH:i') !== $fields['due'] || (int) $date->format('Y') < 1000) {
                    $errors[] = 'Enter a valid due date and time.';
                } else {
                    $dueAt = $date->format('Y-m-d H:i:s');
                }
            }

            if (!$errors) {
                $values = [$fields['name'], $fields['description'], $dueAt, $fields['priority'], $fields['category'], $fields['status']];
                if ($editing) {
                    $stmt = $pdo->prepare(
                        'UPDATE tasks SET task_name = ?, task_description = ?, due_at = ?, priority = ?, category = ?, status = ?
                         WHERE id = ?'
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
                        'INSERT INTO tasks (task_name, task_description, due_at, priority, category, status) VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute($values);
                    $_SESSION['notice'] = 'Task created.';
                }
            }
        } else {
            http_response_code(400);
            $errors[] = 'Unknown form action.';
        }

        if (!$errors) {
            header('Location: index.php', true, 303);
            exit;
        }
        if (http_response_code() === 200) {
            http_response_code(422);
        }
    } elseif (isset($_GET['edit'])) {
        $taskId = taskId(inputText($_GET, 'edit'));
        $task = $taskId === null ? null : findTask($pdo, $taskId);
        if ($task === null) {
            http_response_code(404);
            $errors[] = 'Task not found.';
        } else {
            $fields = array_replace($fields, array_intersect_key($task, $fields));
            $fields['due'] = $task['due_at'] === null ? '' : str_replace(' ', 'T', substr($task['due_at'], 0, 16));
            $showForm = true;
            $editing = true;
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
$search = inputText($_GET, 'search');
$priorityFilter = strtolower(inputText($_GET, 'priority'));
$dueStatusFilter = strtolower(inputText($_GET, 'due_status'));
$view = inputText($_GET, 'view', 'all');
$filters = ['search' => $search, 'priority' => $priorityFilter, 'due_status' => $dueStatusFilter];
$now = time();

foreach ($tasks as $task) {
    $due = $task['due_at'] === null ? null : strtotime($task['due_at']);
    $status = $task['status'] === 'completed' ? 'completed' : ($due !== null && $due < $now ? 'overdue' : 'todo');

    if ($search !== '' && stripos($task['name'], $search) === false && stripos($task['description'], $search) === false) {
        continue;
    }
    if ($priorityFilter !== '' && $priorityFilter !== $task['priority']) {
        continue;
    }
    if ($dueStatusFilter !== '' && $dueStatusFilter !== $status) {
        continue;
    }

    $counts[$status]++;
    if (($view === 'active' && $status === 'completed') || ($view === 'completed' && $status !== 'completed')) {
        continue;
    }
    $task['due'] = $due === null ? 'No due date' : date('M j, Y, g:i A', $due);
    $tasksByStatus[$status][] = $task;
}
$total = array_sum($counts);
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="color-scheme" content="<?= $_SESSION['theme'] ?>">
        <title>Block Tasks | Task inventory</title>
        <link rel="stylesheet" href="style.css">
        <script src="script.js" defer></script>
    </head>
    <body class="<?= $_SESSION['collapsed'] ? 'sidebar-collapsed' : '' ?> <?= $_SESSION['theme'] === 'light' ? 'light-theme' : '' ?>">
        <aside class="sidebar" aria-label="Workspace sidebar">
            <form action="index.php" method="POST" class="control-form">
                <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
                <button class="menu-button" name="action" value="menu" aria-label="Toggle sidebar" aria-expanded="<?= $_SESSION['collapsed'] ? 'false' : 'true' ?>">
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
                <div>
                    <p>John Carl Lasala</p>
                    <small>Personal workspace</small>
                </div>
            </figure>
            <hr>
            <h2 class="nav-label">TASK INVENTORY</h2>
            <nav class="navigation" aria-label="Task views">
                <button type="button" class="selected" aria-current="page">
                    <img src="<?= iconPath('grid') ?>" alt="">
                    <span>My tasks</span>
                </button>
            </nav>
            <h2 class="category-label">CATEGORIES</h2>
            <ul class="categories">
                <?php $markers = ['coursework', 'study', 'personal']; ?>
                <?php foreach ($categories as $index => $category): ?>
                    <li>
                        <span class="category-marker <?= $markers[$index % count($markers)] ?>"></span>
                        <?= escape($category) ?>
                    </li>
                <?php endforeach; ?>
                <li><button type="button" class="text-button" id="open-category" aria-haspopup="dialog">
                    <img src="<?= iconPath($_SESSION['theme'] === 'light' ? 'plus2' : 'plus1') ?>" alt="">
                    <span>New category</span>
                </button></li>
            </ul>
            <div class="appearance">
                <h2>APPEARANCE</h2>
                <div class="switch-wrap">
                    <form action="index.php" method="POST" class="control-form">
                        <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
                        <button class="appearance-switch" name="action" value="theme" aria-label="<?= $_SESSION['theme'] === 'dark' ? 'Use light theme' : 'Use dark theme' ?>" aria-pressed="<?= $_SESSION['theme'] === 'light' ? 'true' : 'false' ?>"><span></span></button>
                    </form>
                </div>
            </div>
        </aside>
        <main class="workspace">
            <header class="page-header">
                <div class="heading">
                    <h1>Task Inventory</h1>
                    <p><?= date('l, j F Y') ?></p>
                </div>
                <form action="index.php" method="GET" class="search bevel" role="search">
                    <img src="<?= iconPath('search') ?>" alt="">
                    <input type="search" name="search" value="<?= escape($search) ?>" aria-label="Search tasks" placeholder="Search task names or descriptions">
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
            <?php if ($errors && !$showForm): ?><p class="errors" role="alert"><?= escape(implode(' ', $errors)) ?></p><?php endif; ?>
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
                    <a href="<?= escape(buildViewLink('all', $filters)) ?>" class="view-filter-btn <?= $view === 'all' ? 'selected' : '' ?>">All tasks · <?= $total ?></a>
                    <a href="<?= escape(buildViewLink('active', $filters)) ?>" class="view-filter-btn <?= $view === 'active' ? 'selected' : '' ?>">Active · <?= $total - $counts['completed'] ?></a>
                    <a href="<?= escape(buildViewLink('completed', $filters)) ?>" class="view-filter-btn <?= $view === 'completed' ? 'selected' : '' ?>">Completed · <?= $counts['completed'] ?></a>
                </div>
                <div class="toolbar-rule"></div>
                <form action="index.php" method="GET" class="sort-filters">
                    <?php if ($search !== ''): ?>
                        <input type="hidden" name="search" value="<?= escape($search) ?>">
                    <?php endif; ?>
                    <?php if ($view !== 'all'): ?>
                        <input type="hidden" name="view" value="<?= escape($view) ?>">
                    <?php endif; ?>

                    <select name="priority" aria-label="Filter by priority">
                        <option value="">All priorities</option>
                        <option value="high" <?= $priorityFilter === 'high' ? 'selected' : '' ?>>High priority</option>
                        <option value="medium" <?= $priorityFilter === 'medium' ? 'selected' : '' ?>>Medium priority</option>
                        <option value="low" <?= $priorityFilter === 'low' ? 'selected' : '' ?>>Low priority</option>
                    </select>

                    <select name="due_status" aria-label="Filter by status">
                        <option value="">Due date</option>
                        <option value="todo" <?= $dueStatusFilter === 'todo' ? 'selected' : '' ?>>To do</option>
                        <option value="overdue" <?= $dueStatusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="completed" <?= $dueStatusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                    <noscript><button type="submit">Apply filters</button></noscript>
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
                                <div class="task-heading">
                                    <form action="index.php" method="POST" class="control-form">
                                        <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
                                        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                        <button type="submit" class="task-checkbox" name="action" value="toggle_complete"
                                            aria-label="<?= $status === 'completed' ? 'Reopen' : 'Complete' ?> <?= escape($task['name']) ?>">
                                            <?php if ($status === 'completed'): ?><img src="<?= iconPath('check') ?>" alt=""><?php endif; ?>
                                        </button>
                                    </form>
                                    <h3><?= escape($task['name']) ?></h3>
                                </div>
                                <p><?= escape($task['description']) ?></p>
                                <footer class="card-footer">
                                    <img src="<?= iconPath('clock') ?>" alt="">
                                    <span><?= escape($task['due']) ?></span>
                                    <div class="card-actions">
                                        <a href="index.php?edit=<?= (int) $task['id'] ?>" aria-label="Edit <?= escape($task['name']) ?>"><img src="<?= iconPath('edit') ?>" alt=""></a>
                                        <form action="index.php" method="POST" class="control-form delete-task">
                                            <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                            <button type="button" aria-haspopup="dialog" aria-label="Delete <?= escape($task['name']) ?>"><img src="<?= iconPath('trash') ?>" alt=""></button>
                                        </form>
                                    </div>
                                </footer>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($status === 'todo'): ?>
                            <div class="add-task">
                                <a href="index.php?new=1" class="text-button"><img src="<?= iconPath('plus1') ?>" alt="">Add task</a>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </section>
        </main>
        <?php if ($showForm): ?>
            <aside id="create-task" class="task-panel" aria-labelledby="create-title">
                <section class="create-panel">
                    <header class="form-heading">
                        <h2 id="create-title"><?= $editing ? 'Edit task' : 'Create task' ?></h2>
                        <a href="index.php" class="secondary-button">Close</a>
                    </header>
                    <p>Add details to plan your next step.</p>
                    <?php if ($errors): ?>
                        <ul class="errors" role="alert">
                            <?php foreach ($errors as $error): ?><li><?= escape($error) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <form action="index.php" method="POST" class="create-form">
                        <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
                        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                        <?php if ($editing && $taskId !== null): ?>
                            <input type="hidden" name="task_id" value="<?= (int) $taskId ?>">
                        <?php endif; ?>
                        <label for="task-name">Task name *</label>
                        <input id="task-name" name="name" value="<?= escape($fields['name']) ?>" placeholder="Enter a task name" aria-describedby="name-help" required minlength="3" maxlength="100" autofocus>
                        <small id="name-help">At least 3 characters, up to 100.</small>
                        <label for="task-description">Description *</label>
                        <textarea id="task-description" name="description" aria-describedby="description-help" required maxlength="255" rows="3" placeholder="Describe what needs to get done."><?= escape($fields['description']) ?></textarea>
                        <small id="description-help">Up to 255 characters.</small>
                        <label for="task-due">Due date &amp; time</label>
                        <input type="datetime-local" id="task-due" name="due" value="<?= escape($fields['due']) ?>">
                        <fieldset class="priority-choices">
                            <legend>Priority</legend>
                            <?php foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $value => $label): ?>
                                <label><input type="radio" name="priority" value="<?= $value ?>" <?= $fields['priority'] === $value ? 'checked' : '' ?>><?= $label ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                        <label for="task-category">Category</label>
                        <select id="task-category" name="category">
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= escape($category) ?>" <?= $fields['category'] === $category ? 'selected' : '' ?>><?= escape($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="task-status">Status</label>
                        <select id="task-status" name="status">
                            <option value="pending" <?= $fields['status'] === 'pending' ? 'selected' : '' ?>>To do</option>
                            <option value="completed" <?= $fields['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                        <footer class="form-actions">
                            <button class="primary-button"><img src="image/dark/icon-form-plus.svg" alt=""><?= $editing ? 'Update task' : 'Create task' ?></button>
                            <button type="reset" class="secondary-button">Reset</button>
                        </footer>
                    </form>
                </section>
            </aside>
        <?php endif; ?>

        <dialog id="delete-dialog" class="board-dialog" aria-labelledby="delete-title" aria-describedby="delete-description">
            <h2 id="delete-title">Delete task</h2>
            <p id="delete-description">Are you sure you want to delete this task?</p>
            <form method="dialog" class="form-actions">
                <button type="button" id="confirm-delete" class="primary-button danger-button">Delete task</button>
                <button class="secondary-button" autofocus>Cancel</button>
            </form>
        </dialog>

        <dialog id="category-dialog" class="board-dialog" aria-labelledby="category-title">
            <h2 id="category-title">Create category</h2>
            <form action="index.php" method="POST" class="create-form">
                <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
                <input type="hidden" name="action" value="create_category">
                <label for="modal-cat-name">Category name</label>
                <input id="modal-cat-name" name="category_name" required maxlength="50" autofocus>
                <div class="form-actions">
                    <button type="submit" class="primary-button">Create category</button>
                    <button type="button" id="cancel-category" class="secondary-button">Cancel</button>
                </div>
            </form>
        </dialog>
    </body>
</html>
