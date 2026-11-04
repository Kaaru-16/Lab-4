<?php
session_start();
// unset($_SESSION['new_tasks']); // for testing in local environment, This will clear all tasks in the session. !!DONT REMOVE THIS PLS!!
date_default_timezone_set('Asia/Taipei');

function escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function iconPath($name) {
    return 'image/' . ($_SESSION['theme'] === 'light' ? 'light' : 'dark') . '/icon-' . $name . '.svg';
}

function posted($key, $default = '') {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function textLength($value) {
    $length = preg_match_all('/./us', $value);
    return $length === false ? PHP_INT_MAX : $length;
}

function taskStatus($task) {
    if ($task['status'] === 'completed') return 'completed';
    return $task['due'] !== '' && strtotime($task['due']) < time() ? 'overdue' : 'todo';
}

$_SESSION['new_tasks'] = $_SESSION['new_tasks'] ?? [];
$_SESSION['categories'] = $_SESSION['categories'] ?? ['Coursework', 'Study', 'Personal'];
$_SESSION['collapsed'] = $_SESSION['collapsed'] ?? false;
$_SESSION['theme'] = $_SESSION['theme'] ?? 'dark';
$_SESSION['token'] = $_SESSION['token'] ?? bin2hex(random_bytes(32));
$fields = ['name' => '', 'description' => '', 'due' => '', 'priority' => 'medium', 'category' => 'Coursework', 'status' => 'todo'];
$errors = [];
$showForm = isset($_GET['new']);
$showCategoryForm = isset($_GET['new_category']);
$categoryName = '';
$editing = false;
$taskId = null;
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);

if (isset($_GET['edit'])) {
    $requestedTaskId = is_string($_GET['edit']) && ctype_digit($_GET['edit']) ? (int) $_GET['edit'] : null;
    if ($requestedTaskId !== null && array_key_exists($requestedTaskId, $_SESSION['new_tasks'])) {
        $taskId = $requestedTaskId;
        $fields = array_merge($fields, $_SESSION['new_tasks'][$taskId]);
        $showForm = true;
        $editing = true;
    } else {
        http_response_code(404);
        $errors[] = 'Task not found.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = posted('action');
    if (!hash_equals($_SESSION['token'], posted('token'))) {
        http_response_code(403);
        $errors[] = 'Your form expired. Reload the page and try again.';

    // Toggle Sidebar Function
    } elseif ($action === 'menu') {
        $_SESSION['collapsed'] = !$_SESSION['collapsed'];

    // Toggle Theme Function
    } elseif ($action === 'theme') {
        $_SESSION['theme'] = $_SESSION['theme'] === 'dark' ? 'light' : 'dark';

    // Create Category Function
    } elseif ($action === 'category_create') {
        $showCategoryForm = true;
        $categoryName = posted('category_name');
        if ($categoryName === '' || textLength($categoryName) > 30) {
            $errors[] = 'Category name must contain 1 to 30 characters.';
        }
        $existingCategories = array_map('strtolower', $_SESSION['categories']);
        if (in_array(strtolower($categoryName), $existingCategories, true)) {
            $errors[] = 'That category already exists.';
        }
        if (!$errors) {
            $_SESSION['categories'][] = $categoryName;
            $_SESSION['notice'] = 'Category created.';
        }

    // Create and Update/Edit Task Function
    } elseif ($action === 'create' || $action === 'update') {
        $editing = $action === 'update';
        $showForm = true;
        if ($editing) {
            $postedTaskId = posted('task_id');
            $taskId = $postedTaskId;
            if ($postedTaskId === '' || !ctype_digit($postedTaskId) || !array_key_exists((int) $postedTaskId, $_SESSION['new_tasks'])) {
                http_response_code(404);
                $errors[] = 'Task not found.';
            } else {
                $taskId = (int) $postedTaskId;
            }
        }
        foreach ($fields as $key => $default) $fields[$key] = posted($key, $default);
        if (textLength($fields['name']) < 3 || textLength($fields['name']) > 100) {
            $errors[] = 'Task name must contain 3 to 100 characters.';
        }
        if ($fields['description'] === '' || textLength($fields['description']) > 255) {
            $errors[] = 'Description must contain 1 to 255 characters.';
        }
        if (!in_array($fields['priority'], ['high', 'medium', 'low'], true)) $errors[] = 'Choose a valid priority.';
        if (!in_array($fields['category'], $_SESSION['categories'], true)) $errors[] = 'Choose a valid category.';
        if (!in_array($fields['status'], ['todo', 'completed'], true)) $errors[] = 'Choose a valid status.';
        if ($fields['due'] !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $fields['due']);
            if (!$date || $date->format('Y-m-d\TH:i') !== $fields['due']) $errors[] = 'Enter a valid due date and time.';
        }
        if (!$errors) {
            if ($editing) {
                $_SESSION['new_tasks'][$taskId] = $fields;
                $_SESSION['notice'] = 'Task updated.';
            } else {
                $_SESSION['new_tasks'][] = $fields;
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
    if (http_response_code() === 200) http_response_code(422);
}

$samples = [
    ['Prepare Lab Activity 4', 'Review the requirements and finish the design draft.', 'Today, 10:00 AM', 'high', 'Coursework', 'todo'],
    ['Review PHP forms', 'Read the notes on POST and validation.', 'Today, 1:00 PM', 'medium', 'Study', 'todo'],
    ['Organize project files', 'Group references, screenshots, and notes.', 'Tomorrow, 9:00 AM', 'low', 'Personal', 'todo'],
    ['Design the task manager', 'Refine the board, form, and task controls.', 'Wednesday, October 23, 11:30 AM', 'high', 'Coursework', 'overdue'],
    ['Study session handling', 'Review task persistence across page reloads.', 'Yesterday, 2:00 PM', 'medium', 'Study', 'overdue'],
    ['Read the lab brief', 'List the required CRUD operations.', 'Completed today', 'low', 'Coursework', 'completed'],
    ['Set up the workspace', 'Create the project folder and collect assets.', 'Completed today', 'low', 'Coursework', 'completed'],
];
$columns = ['todo' => 'To do', 'overdue' => 'Overdue', 'completed' => 'Completed'];
$tasks = [];
foreach ($samples as [$name, $description, $due, $priority, $category, $status]) {
    $tasks[] = compact('name', 'description', 'due', 'priority', 'category', 'status') + ['sample' => true];
}
foreach ($_SESSION['new_tasks'] as $taskId => $task) {
    $task['status'] = taskStatus($task);
    $task['due'] = $task['due'] !== '' ? date('M j, Y, g:i A', strtotime($task['due'])) : 'No due date';
    $task['session_id'] = $taskId;
    $tasks[] = $task;
}
$counts = array_fill_keys(array_keys($columns), 0);
foreach ($tasks as $task) $counts[$task['status']]++;
$total = count($tasks);
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="color-scheme" content="<?= $_SESSION['theme'] ?>">
        <title>Block Tasks | Task inventory</title>
        <link rel="stylesheet" href="style.css">
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
                <?php foreach ($_SESSION['categories'] as $category): ?>
                    <li>
                        <span class="category-marker <?= in_array($category, ['Coursework', 'Study', 'Personal'], true) ? strtolower($category) : '' ?>"></span>
                        <?= escape($category) ?>
                    </li>
                <?php endforeach; ?>
                <li><a href="index.php?new_category=1" class="text-button">
                    <img src="<?= iconPath($_SESSION['theme'] === 'light' ? 'plus2' : 'plus1') ?>" alt="">
                    <span>New category</span>
                </a></li>
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
                    <h1>Task inventory</h1>
                    <p>Sunday, 1 November 2026</p>
                </div>
                <div class="search bevel">
                    <img src="<?= iconPath('search') ?>" alt="">
                    <span>Search task names or descriptions</span>
                </div>
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
                    <p><?= $total ?> tasks this week. Plan, track, and complete.</p>
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
                    <button type="button" class="selected">All tasks · <?= $total ?></button>
                    <button type="button">Active · <?= $total - $counts['completed'] ?></button>
                    <button type="button">Completed · <?= $counts['completed'] ?></button>
                </div>
                <div class="toolbar-rule"></div>
                <div class="sort-filters">
                    <button type="button">
                        <img src="<?= iconPath('filter') ?>" alt="">
                        <span>All priorities</span>
                        <img src="<?= iconPath('chevron') ?>" alt="">
                    </button>
                    <button type="button">
                        <span>Due date</span>
                        <img src="<?= iconPath('chevron') ?>" alt="">
                    </button>
                </div>
            </div>
            <section class="task-board" aria-label="Task inventory board">
                <?php foreach ($columns as $status => $label): ?>
                    <section class="task-column <?= $status ?>" aria-labelledby="column-<?= $status ?>">
                        <header class="column-heading">
                            <span class="status-marker" aria-hidden="true"></span>
                            <h2 id="column-<?= $status ?>"><?= $label ?></h2>
                            <span class="task-count"><?= $counts[$status] ?></span>
                        </header>
                        <?php foreach ($tasks as $task): ?>
                            <?php if ($task['status'] !== $status) continue; ?>
                            <article class="task-card <?= $status === 'completed' ? 'is-complete' : '' ?>">
                                <header class="card-meta">
                                    <span class="priority <?= $status === 'completed' ? 'low' : escape($task['priority']) ?>"><?= $status === 'completed' ? 'Completed' : ucfirst(escape($task['priority'])) . ' priority' ?></span>
                                    <span><?= escape($task['category']) ?></span>
                                </header>
                                <h3 class="task-heading">
                                    <span class="task-checkbox" aria-hidden="true">
                                        <?php if ($status === 'completed'): ?><img src="<?= iconPath('check') ?>" alt=""><?php endif; ?>
                                    </span>
                                    <span><?= escape($task['name']) ?></span>
                                </h3>
                                <p><?= escape($task['description']) ?></p>
                                <footer class="card-footer">
                                    <img src="<?= iconPath('clock') ?>" alt="">
                                    <span><?= escape($task['due']) ?></span>
                                    <?php if (!empty($task['sample'])): ?>
                                        <div class="card-actions">
                                            <button type="button" aria-label="Edit <?= escape($task['name']) ?>"><img src="<?= iconPath('edit') ?>" alt=""></button>
                                            <button type="button" aria-label="Delete <?= escape($task['name']) ?>"><img src="<?= iconPath('trash') ?>" alt=""></button>
                                        </div>
                                    <?php else: ?>
                                        <div class="card-actions">
                                            <form action="index.php" method="GET" class="control-form">
                                                <input type="hidden" name="edit" value="<?= (int) $task['session_id'] ?>">
                                                <button type="submit" aria-label="Edit <?= escape($task['name']) ?>"><img src="<?= iconPath('edit') ?>" alt=""></button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </footer>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($status === 'todo'): ?>
                            <div class="add-task">
                                <button type="button" class="text-button"><img src="<?= iconPath('plus1') ?>" alt="">Add task</button>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </section>
        </main>
        <?php if ($showForm || $showCategoryForm): ?>
            <aside id="create-task" class="task-panel <?= $showCategoryForm ? 'category-panel' : '' ?>" aria-labelledby="create-title">
                <section class="create-panel">
                    <header class="form-heading">
                        <h2 id="create-title"><?= $showCategoryForm ? 'Create Category' : ($editing ? 'Edit task' : 'Create task') ?></h2>
                        <?php if (!$showCategoryForm): ?><a href="index.php" class="secondary-button">Close</a><?php endif; ?>
                    </header>
                    <?php if (!$showCategoryForm): ?><p>Add details to plan your next step.</p><?php endif; ?>
                    <?php if ($errors): ?>
                        <ul class="errors" role="alert">
                            <?php foreach ($errors as $error): ?><li><?= escape($error) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($showCategoryForm): ?>
                        <form action="index.php" method="POST" class="create-form category-form">
                            <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
                            <input type="hidden" name="action" value="category_create">
                            <label for="category-name">Category name</label>
                            <input id="category-name" name="category_name" value="<?= escape($categoryName) ?>" placeholder="Enter a category name" required maxlength="30">
                            <footer class="form-actions">
                                <button class="primary-button"><img src="image/dark/icon-form-plus.svg" alt="">Create Category</button>
                                <a href="index.php" class="secondary-button">Cancel</a>
                            </footer>
                        </form>
                    <?php else: ?>
                    <form action="index.php" method="POST" class="create-form">
                        <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
                        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                        <?php if ($editing): ?><input type="hidden" name="task_id" value="<?= escape($taskId) ?>"><?php endif; ?>
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
                            <?php foreach ($_SESSION['categories'] as $category): ?><option <?= $fields['category'] === $category ? 'selected' : '' ?>><?= escape($category) ?></option><?php endforeach; ?>
                        </select>
                        <label for="task-status">Status</label>
                        <select id="task-status" name="status">
                            <option value="todo" <?= $fields['status'] === 'todo' ? 'selected' : '' ?>>To do</option>
                            <option value="completed" <?= $fields['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                        <footer class="form-actions">
                            <button class="primary-button"><img src="image/dark/icon-form-plus.svg" alt=""><?= $editing ? 'Update task' : 'Create task' ?></button>
                            <button type="reset" class="secondary-button">Reset</button>
                        </footer>
                    </form>
                    <?php endif; ?>
                </section>
            </aside>
        <?php endif; ?>
    </body>
</html>
