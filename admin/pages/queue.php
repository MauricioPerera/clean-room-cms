<?php
/**
 * Clean Room CMS - Queue Monitor + Security Settings + Comments + Media
 */

function cr_admin_queue_monitor(): void {
    $db = cr_db();
    $table = $db->prefix . 'queue';

    // Check if queue table exists
    if (!$db->get_var("SHOW TABLES LIKE '{$table}'")) {
        echo '<div class="admin-header"><h1>Queue Monitor</h1></div>';
        echo '<p>Queue table not installed. Run the worker once to initialize.</p>';
        return;
    }

    $stats = CR_Queue::stats();
    $msg = $_GET['msg'] ?? '';
    $filter = $_GET['filter'] ?? 'all';

    $where = match ($filter) {
        'pending'   => "WHERE status = 'pending'",
        'running'   => "WHERE status = 'running'",
        'completed' => "WHERE status = 'completed'",
        'failed'    => "WHERE status IN ('failed', 'dead')",
        default     => "",
    };

    $jobs = $db->get_results("SELECT * FROM `{$table}` {$where} ORDER BY id DESC LIMIT 50");
?>
    <div class="admin-header"><h1>Queue Monitor</h1></div>

    <?php if ($msg === 'retried'): ?><div class="admin-notice success">Job retried.</div><?php endif; ?>
    <?php if ($msg === 'cleaned'): ?><div class="admin-notice success">Old jobs cleaned.</div><?php endif; ?>

    <div class="dashboard-cards" style="margin-bottom:16px">
        <div class="card"><div class="card-number"><?php echo (int) $stats['pending']; ?></div><div class="card-label">Pending</div></div>
        <div class="card"><div class="card-number"><?php echo (int) $stats['running']; ?></div><div class="card-label">Running</div></div>
        <div class="card"><div class="card-number"><?php echo (int) $stats['completed']; ?></div><div class="card-label">Completed</div></div>
        <div class="card"><div class="card-number"><?php echo (int) $stats['dead']; ?></div><div class="card-label">Dead</div></div>
    </div>

    <div style="margin-bottom:16px;display:flex;gap:8px">
        <a href="?page=queue" class="btn btn-sm <?php echo $filter === 'all' ? 'btn-primary' : 'btn-secondary'; ?>">All</a>
        <a href="?page=queue&filter=pending" class="btn btn-sm <?php echo $filter === 'pending' ? 'btn-primary' : 'btn-secondary'; ?>">Pending</a>
        <a href="?page=queue&filter=failed" class="btn btn-sm <?php echo $filter === 'failed' ? 'btn-primary' : 'btn-secondary'; ?>">Failed</a>
        <a href="?page=queue&action=cleanup&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="btn btn-sm btn-secondary" onclick="return confirm('Clean completed/dead jobs older than 7 days?')">Cleanup</a>
    </div>

    <table class="admin-table">
        <thead><tr><th>ID</th><th>Hook</th><th>Status</th><th>Attempts</th><th>Scheduled</th><th>Error</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): ?>
            <tr>
                <td><?php echo $j->id; ?></td>
                <td><code><?php echo esc_html($j->hook); ?></code></td>
                <td><span class="status-badge status-<?php echo $j->status === 'completed' ? 'publish' : ($j->status === 'dead' ? 'trash' : 'draft'); ?>"><?php echo esc_html($j->status); ?></span></td>
                <td><?php echo (int) $j->attempts; ?>/<?php echo (int) $j->max_attempts ?: '∞'; ?></td>
                <td><?php echo date('M j H:i', strtotime($j->scheduled_at)); ?></td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($j->last_error ?? ''); ?></td>
                <td>
                    <?php if ($j->status === 'dead' || $j->status === 'failed'): ?>
                        <a href="?page=queue&action=retry&id=<?php echo $j->id; ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>">Retry</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($jobs)): ?>
            <tr><td colspan="7">No jobs found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php
}

// =============================================
// Security Settings
// =============================================

function cr_admin_security_settings(): void {
    $msg = $_GET['msg'] ?? '';
    $api_limit = get_option('cr_api_rate_limit_val', 100);
    $login_limit = get_option('cr_login_rate_limit_val', 5);
    $login_window = get_option('cr_login_rate_window_val', 300);
?>
    <div class="admin-header"><h1>Security Settings</h1></div>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">Security settings saved.</div><?php endif; ?>

    <form method="post" action="?page=security">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="_action" value="save_security">

        <div class="admin-section">
            <h2>API Rate Limiting</h2>
            <div class="form-row">
                <div class="form-group" style="flex:1">
                    <label>Max requests per minute</label>
                    <input type="number" name="api_rate_limit" value="<?php echo (int) $api_limit; ?>" min="10" max="10000" style="width:120px">
                </div>
            </div>
        </div>

        <div class="admin-section">
            <h2>Login Protection</h2>
            <div class="form-row">
                <div class="form-group" style="flex:1">
                    <label>Max failed attempts before lockout</label>
                    <input type="number" name="login_limit" value="<?php echo (int) $login_limit; ?>" min="1" max="100" style="width:100px">
                </div>
                <div class="form-group" style="flex:1">
                    <label>Lockout duration (seconds)</label>
                    <input type="number" name="login_window" value="<?php echo (int) $login_window; ?>" min="60" max="86400" style="width:120px">
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Security Settings</button>
        </div>
    </form>
<?php
}

function cr_admin_save_security(): void {
    update_option('cr_api_rate_limit_val', (int) ($_POST['api_rate_limit'] ?? 100), 'no');
    update_option('cr_login_rate_limit_val', (int) ($_POST['login_limit'] ?? 5), 'no');
    update_option('cr_login_rate_window_val', (int) ($_POST['login_window'] ?? 300), 'no');
    header('Location: ' . CR_SITE_URL . '/admin/?page=security&msg=saved');
    exit;
}

// =============================================
// Comments Moderation
// =============================================

function cr_admin_comments(): void {
    $db = cr_db();
    $filter = $_GET['filter'] ?? 'all';
    $msg = $_GET['msg'] ?? '';

    $where = match ($filter) {
        'pending' => "WHERE c.comment_approved = '0'",
        'approved' => "WHERE c.comment_approved = '1'",
        'spam' => "WHERE c.comment_approved = 'spam'",
        default => "",
    };

    $comments = $db->get_results(
        "SELECT c.*, p.post_title FROM `{$db->prefix}comments` c
         LEFT JOIN `{$db->prefix}posts` p ON c.comment_post_ID = p.ID
         {$where} ORDER BY c.comment_date DESC LIMIT 50"
    );

    $counts = [
        'all'      => (int) $db->get_var("SELECT COUNT(*) FROM `{$db->prefix}comments`"),
        'pending'  => (int) $db->get_var("SELECT COUNT(*) FROM `{$db->prefix}comments` WHERE comment_approved = '0'"),
        'approved' => (int) $db->get_var("SELECT COUNT(*) FROM `{$db->prefix}comments` WHERE comment_approved = '1'"),
    ];
?>
    <div class="admin-header"><h1>Comments</h1></div>

    <?php if ($msg === 'updated'): ?><div class="admin-notice success">Comment updated.</div><?php endif; ?>
    <?php if ($msg === 'deleted'): ?><div class="admin-notice success">Comment deleted.</div><?php endif; ?>

    <div style="margin-bottom:16px;display:flex;gap:8px">
        <a href="?page=comments" class="btn btn-sm <?php echo $filter === 'all' ? 'btn-primary' : 'btn-secondary'; ?>">All (<?php echo $counts['all']; ?>)</a>
        <a href="?page=comments&filter=pending" class="btn btn-sm <?php echo $filter === 'pending' ? 'btn-primary' : 'btn-secondary'; ?>">Pending (<?php echo $counts['pending']; ?>)</a>
        <a href="?page=comments&filter=approved" class="btn btn-sm <?php echo $filter === 'approved' ? 'btn-primary' : 'btn-secondary'; ?>">Approved (<?php echo $counts['approved']; ?>)</a>
    </div>

    <table class="admin-table">
        <thead><tr><th>Author</th><th>Comment</th><th>On Post</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($comments as $c): ?>
            <tr>
                <td><strong><?php echo esc_html($c->comment_author); ?></strong><br><small><?php echo esc_html($c->comment_author_email); ?></small></td>
                <td style="max-width:300px"><?php echo esc_html(mb_substr($c->comment_content, 0, 120)); ?><?php echo mb_strlen($c->comment_content) > 120 ? '...' : ''; ?></td>
                <td><a href="?page=post-edit&id=<?php echo $c->comment_post_ID; ?>"><?php echo esc_html($c->post_title ?? '#' . $c->comment_post_ID); ?></a></td>
                <td><?php echo date('M j, Y', strtotime($c->comment_date)); ?></td>
                <td><span class="status-badge status-<?php echo $c->comment_approved === '1' ? 'publish' : 'draft'; ?>"><?php echo $c->comment_approved === '1' ? 'Approved' : ($c->comment_approved === 'spam' ? 'Spam' : 'Pending'); ?></span></td>
                <td>
                    <?php if ($c->comment_approved !== '1'): ?>
                        <a href="?page=comments&action=approve&id=<?php echo $c->comment_ID; ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>">Approve</a>
                    <?php endif; ?>
                    <?php if ($c->comment_approved !== 'spam'): ?>
                        <a href="?page=comments&action=spam&id=<?php echo $c->comment_ID; ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>">Spam</a>
                    <?php endif; ?>
                    <a href="?page=comments&action=delete&id=<?php echo $c->comment_ID; ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="text-danger" onclick="return confirm('Delete this comment?')">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($comments)): ?>
            <tr><td colspan="6">No comments found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php
}

// =============================================
// Media Library
// =============================================

function cr_admin_media(): void {
    $db = cr_db();
    $msg = $_GET['msg'] ?? '';
    $media = $db->get_results(
        "SELECT * FROM `{$db->prefix}posts` WHERE post_type = 'attachment' ORDER BY post_date DESC LIMIT 50"
    );
?>
    <div class="admin-header">
        <h1>Media Library</h1>
        <form method="post" enctype="multipart/form-data" action="?page=media" style="display:inline">
            <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
            <input type="hidden" name="_action" value="upload_media">
            <label class="btn btn-primary" style="cursor:pointer">
                Upload File <input type="file" name="media_file" style="display:none" onchange="this.form.submit()">
            </label>
        </form>
    </div>

    <?php if ($msg === 'uploaded'): ?><div class="admin-notice success">File uploaded.</div><?php endif; ?>
    <?php if ($msg === 'deleted'): ?><div class="admin-notice success">File deleted.</div><?php endif; ?>
    <?php if ($msg === 'error'): ?><div class="admin-notice error">Upload failed.</div><?php endif; ?>

    <?php if (!empty($media)): ?>
    <div class="media-grid">
        <?php foreach ($media as $m):
            $url = CR_UPLOAD_URL . '/' . basename($m->guid);
            $is_image = str_starts_with($m->post_mime_type, 'image/');
        ?>
        <div class="media-item">
            <?php if ($is_image): ?>
                <div class="media-thumb" style="background-image:url('<?php echo esc_url($url); ?>')"></div>
            <?php else: ?>
                <div class="media-thumb media-file">📄</div>
            <?php endif; ?>
            <div class="media-info">
                <strong><?php echo esc_html($m->post_title); ?></strong>
                <small><?php echo esc_html($m->post_mime_type); ?></small>
            </div>
            <div class="media-actions">
                <input type="text" value="<?php echo esc_attr($url); ?>" readonly onclick="this.select()" class="input-full" style="font-size:.75em">
                <a href="?page=media&action=delete&id=<?php echo $m->ID; ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="text-danger" onclick="return confirm('Delete this file?')">Delete</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p>No media files yet. Upload your first file above.</p>
    <?php endif; ?>
<?php
}

function cr_admin_upload_media(): void {
    if (empty($_FILES['media_file']['tmp_name'])) {
        header('Location: ' . CR_SITE_URL . '/admin/?page=media&msg=error');
        exit;
    }

    $file = $_FILES['media_file'];
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv'];

    // Server-side MIME validation (don't trust client-reported type)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actual_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($actual_type, $allowed)) {
        header('Location: ' . CR_SITE_URL . '/admin/?page=media&msg=error');
        exit;
    }

    // Create upload directory by date
    $subdir = date('Y/m');
    $upload_dir = CR_UPLOAD_PATH . '/' . $subdir;
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    // Sanitize filename
    $name = preg_replace('/[^a-z0-9._-]/', '', strtolower($file['name']));
    $name = $name ?: 'file-' . time();

    // Ensure unique
    $base = pathinfo($name, PATHINFO_FILENAME);
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $i = 1;
    while (file_exists($upload_dir . '/' . $name)) {
        $name = $base . '-' . $i . '.' . $ext;
        $i++;
    }

    if (!move_uploaded_file($file['tmp_name'], $upload_dir . '/' . $name)) {
        header('Location: ' . CR_SITE_URL . '/admin/?page=media&msg=error');
        exit;
    }

    $url = CR_UPLOAD_URL . '/' . $subdir . '/' . $name;

    cr_insert_post([
        'post_title'     => $base,
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_type'      => 'attachment',
        'post_mime_type' => $file['type'],
        'post_author'    => get_current_user_id(),
        'guid'           => $url,
    ]);

    header('Location: ' . CR_SITE_URL . '/admin/?page=media&msg=uploaded');
    exit;
}
