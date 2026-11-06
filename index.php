<?php
session_start();
//unset($_SESSION['new_tasks']); 
//unset($_SESSION['categories']); 
date_default_timezone_set('Asia/Taipei');
$minimumDue = date('Y-m-d\TH:i');

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

function buildViewLink($view, $params) {
    if ($view === 'all') unset($params['view']);
    else $params['view'] = $view;
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

function taskStatus($task) {
    if ($task['status'] === 'completed') return 'completed';
    return $task['due'] !== '' && strtotime($task['due']) < time() ? 'overdue' : 'todo';
}

$_SESSION['new_tasks'] = $_SESSION['new_tasks'] ?? [];
$_SESSION['categories'] = $_SESSION['categories'] ?? ['Coursework', 'Study', 'Personal'];
// Load the Figma examples once, then treat them like regular editable tasks.
if (empty($_SESSION['sample_tasks_loaded'])) {
    $sampleTasks = [
        ['Prepare Lab Activity 4', 'Review the requirements and finish the design draft.', 'tomorrow 10:00', 'high', 'Coursework', 'todo'],
        ['Review PHP forms', 'Read the notes on POST and validation.', 'tomorrow 13:00', 'medium', 'Study', 'todo'],
        ['Organize project files', 'Group references, screenshots, and notes.', '+2 days 09:00', 'low', 'Personal', 'todo'],
        ['Design the task manager', 'Refine the board, form, and task controls.', 'yesterday 11:30', 'high', 'Coursework', 'todo'],
        ['Study session handling', 'Review task persistence across page reloads.', 'yesterday 14:00', 'medium', 'Study', 'todo'],
        ['Read the lab brief', 'List the required CRUD operations.', 'today 09:00', 'low', 'Coursework', 'completed'],
        ['Set up the workspace', 'Create the project folder and collect assets.', 'today 09:00', 'low', 'Coursework', 'completed'],
    ];
    $existingNames = array_column($_SESSION['new_tasks'], 'name');
    foreach ($sampleTasks as [$name, $description, $relativeDue, $priority, $category, $status]) {
        if (in_array($name, $existingNames, true)) continue;
        if (!in_array($category, $_SESSION['categories'], true)) $_SESSION['categories'][] = $category;
        $due = date('Y-m-d\TH:i', strtotime($relativeDue));
        $_SESSION['new_tasks'][] = compact('name', 'description', 'due', 'priority', 'category', 'status') + ['reminder' => ''];
    }
    $_SESSION['sample_tasks_loaded'] = true;
}
$_SESSION['collapsed'] = $_SESSION['collapsed'] ?? false;
$_SESSION['theme'] = $_SESSION['theme'] ?? 'dark';
$_SESSION['token'] = $_SESSION['token'] ?? bin2hex(random_bytes(32));
$fields = ['name' => '', 'description' => '', 'due' => '', 'priority' => 'medium', 'category' => 'Coursework', 'status' => 'todo', 'reminder' => ''];
$selectedCategory = isset($_GET['category']) && is_string($_GET['category']) && in_array($_GET['category'], $_SESSION['categories'], true)
    ? $_GET['category']
    : null;
$errors = [];
$showForm = isset($_GET['new']);
$showCategoryForm = isset($_GET['new_category']);
$pageParams = array_filter($_GET, 'is_string');
$pageUrl = 'index.php' . ($pageParams ? '?' . http_build_query($pageParams) : '');
$showCategoryDelete = false;
$deleteCategoryName = '';
$categoryName = '';
$editing = false;
$taskId = null;
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);

if (isset($_GET['delete_category'])) {
    $deleteCategoryName = is_string($_GET['delete_category']) ? $_GET['delete_category'] : '';
    if (in_array($deleteCategoryName, $_SESSION['categories'], true)) {
        $showCategoryDelete = true;
    } else {
        http_response_code(404);
        $errors[] = 'Category not found.';
    }
}

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

    } elseif ($action === 'menu') {
        $_SESSION['collapsed'] = !$_SESSION['collapsed'];
    } elseif ($action === 'toggle_complete') {
        $postedTaskId = posted('task_id');
        if ($postedTaskId !== '' && ctype_digit($postedTaskId) && array_key_exists((int) $postedTaskId, $_SESSION['new_tasks'])) {
            $taskId = (int) $postedTaskId;
            $currentStatus = $_SESSION['new_tasks'][$taskId]['status'] ?? 'todo';
            $_SESSION['new_tasks'][$taskId]['status'] = ($currentStatus === 'completed') ? 'todo' : 'completed';
            $_SESSION['notice'] = 'Task status updated.';
        } else {
            http_response_code(404);
            $errors[] = 'Task not found.';
        }
    } elseif ($action === 'theme') {
        $_SESSION['theme'] = $_SESSION['theme'] === 'dark' ? 'light' : 'dark';
    } elseif ($action === 'delete') {
        $postedTaskId = posted('task_id');
        if ($postedTaskId !== '' && ctype_digit($postedTaskId) && array_key_exists((int) $postedTaskId, $_SESSION['new_tasks'])) {
            unset($_SESSION['new_tasks'][(int) $postedTaskId]);
            $_SESSION['notice'] = 'Task deleted.';
        } else {
            http_response_code(404);
            $errors[] = 'Task not found.';
        }

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

    } elseif ($action === 'category_delete') {
        $categoryName = posted('category_name');
        $categoryIndex = array_search($categoryName, $_SESSION['categories'], true);
        if ($categoryIndex === false) {
            http_response_code(404);
            $errors[] = 'Category not found.';
        } else {
            unset($_SESSION['categories'][$categoryIndex]);
            $_SESSION['categories'] = array_values($_SESSION['categories']);
            foreach ($_SESSION['new_tasks'] as $id => $task) {
                if ($task['category'] === $categoryName) unset($_SESSION['new_tasks'][$id]);
            }
            $_SESSION['notice'] = 'Category and its tasks deleted.';
        }

    } elseif ($action === 'create' || $action === 'update') {
        $editing = $action === 'update';
        $showForm = true;
        if ($editing) {
            $postedTaskId = posted('task_id');
            if ($postedTaskId === '' || !ctype_digit($postedTaskId) || !array_key_exists((int) $postedTaskId, $_SESSION['new_tasks'])) {
                http_response_code(404);
                $errors[] = 'Task not found.';
                $editing = false;
            } else {
                $taskId = (int) $postedTaskId;
            }
        }
        foreach ($fields as $key => $default) $fields[$key] = posted($key, $default);
        $fields['reminder'] = posted('reminder') === '1' ? '1' : '';
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
            if (!$date || $date->format('Y-m-d\TH:i') !== $fields['due']) {
                $errors[] = 'Enter a valid due date and time.';
            } elseif ($fields['due'] < $minimumDue) {
                $errors[] = 'Choose a current or future due date and time.';
            }
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
        if ($action === 'menu' && ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
            header('Content-Type: application/json');
            echo json_encode(['collapsed' => $_SESSION['collapsed']]);
            exit;
        }
        header('Location: ' . (in_array($action, ['menu', 'theme'], true) ? $pageUrl : 'index.php'), true, 303);
        exit;
    }
    if (http_response_code() === 200) http_response_code(422);
}

$columns = ['todo' => 'To do', 'overdue' => 'Overdue', 'completed' => 'Completed'];
$tasks = [];
foreach ($_SESSION['new_tasks'] as $id => $task) {
    $task['status'] = taskStatus($task);
    $task['due'] = $task['due'] !== '' ? date('M j, Y, g:i A', strtotime($task['due'])) : 'No due date';
    $task['session_id'] = $id;
    $tasks[] = $task;
}
$searchQuery = trim($pageParams['search'] ?? '');
$priorityFilter = $pageParams['priority'] ?? '';
$dueStatusFilter = $pageParams['due_status'] ?? '';
$viewFilter = $pageParams['view'] ?? 'all';
$tasks = array_filter($tasks, static function ($task) use ($searchQuery, $priorityFilter, $dueStatusFilter, $selectedCategory) {
    return ($searchQuery === '' || stripos($task['name'], $searchQuery) !== false || stripos($task['description'], $searchQuery) !== false)
        && ($priorityFilter === '' || strcasecmp($task['priority'], $priorityFilter) === 0)
        && ($dueStatusFilter === '' || strcasecmp($task['status'], $dueStatusFilter) === 0)
        && ($selectedCategory === null || $task['category'] === $selectedCategory);
});
// Count before selecting a view so the tab totals stay stable.
$counts = array_fill_keys(array_keys($columns), 0);
foreach ($tasks as $task) $counts[$task['status']]++;
$total = count($tasks);
$tasks = array_filter($tasks, static function ($task) use ($viewFilter) {
    return ($viewFilter !== 'active' || $task['status'] !== 'completed')
        && ($viewFilter !== 'completed' || $task['status'] === 'completed');
});

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
                <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
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
                <?php foreach ($_SESSION['categories'] as $category): ?>
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
                    <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
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
                    <a href="<?= escape(buildViewLink('all', $pageParams)) ?>" class="view-filter-btn <?= $viewFilter === 'all' ? 'selected' : '' ?>">All tasks · <?= $total ?></a>
                    <a href="<?= escape(buildViewLink('active', $pageParams)) ?>" class="view-filter-btn <?= $viewFilter === 'active' ? 'selected' : '' ?>">Active · <?= $total - $counts['completed'] ?></a>
                    <a href="<?= escape(buildViewLink('completed', $pageParams)) ?>" class="view-filter-btn <?= $viewFilter === 'completed' ? 'selected' : '' ?>">Completed · <?= $counts['completed'] ?></a>
                </div>
                <div class="toolbar-rule"></div>
                <form action="index.php" method="GET" class="sort-filters">
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
                        <?php foreach ($tasks as $task): ?>
                            <?php if ($task['status'] !== $status) continue; ?>
                            <article class="task-card <?= $status === 'completed' ? 'is-complete' : '' ?>">
                                <header class="card-meta">
                                    <span class="priority <?= $status === 'completed' ? 'low' : escape($task['priority']) ?>"><?= $status === 'completed' ? 'Completed' : ucfirst(escape($task['priority'])) . ' priority' ?></span>
                                    <span><?= escape($task['category']) ?></span>
                                </header>
                                <header class="task-heading">
                                    <form id="toggle-form-<?= (int) $task['session_id'] ?>" action="index.php" method="POST" class="completion-form">
                                        <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
                                        <input type="hidden" name="action" value="toggle_complete">
                                        <input type="hidden" name="task_id" value="<?= (int) $task['session_id'] ?>">
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
                                        <a href="index.php?edit=<?= (int) $task['session_id'] ?>" aria-label="Edit <?= escape($task['name']) ?>"><img src="<?= iconPath('edit') ?>" alt=""></a>
                                        <form id="delete-form-<?= (int) $task['session_id'] ?>" action="index.php" method="POST" class="control-form">
                                            <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="task_id" value="<?= (int) $task['session_id'] ?>">
                                            <button type="button" data-delete-task="<?= (int) $task['session_id'] ?>" aria-label="Delete <?= escape($task['name']) ?>"><img src="<?= iconPath('trash') ?>" alt=""></button>
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
                            <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
                            <input type="hidden" name="action" value="category_delete">
                            <input type="hidden" name="category_name" value="<?= escape($deleteCategoryName) ?>">
                            <footer class="form-actions">
                                <button class="primary-button delete-button">Delete Category</button>
                                <a href="index.php" class="secondary-button">Cancel</a>
                            </footer>
                        </form>
                    <?php elseif ($showCategoryForm): ?>
                        <form action="index.php" method="POST" class="create-form category-form">
                            <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
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
                            <input type="hidden" name="token" value="<?= escape($_SESSION['token']) ?>">
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
                            <input type="datetime-local" id="task-due" name="due" min="<?= escape($minimumDue) ?>" value="<?= escape($fields['due']) ?>" aria-describedby="due-help">
                            <small id="due-help">Choose today or a future date and time.</small>
                            <fieldset class="priority-choices">
                                <legend>Priority</legend>
                                <?php foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $value => $label): ?>
                                    <label><input type="radio" name="priority" value="<?= $value ?>" <?= $fields['priority'] === $value ? 'checked' : '' ?>><?= $label ?></label>
                                <?php endforeach; ?>
                            </fieldset>
                            <label for="task-category">Category</label>
                            <select id="task-category" name="category">
                                <?php foreach ($_SESSION['categories'] as $category): ?>
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