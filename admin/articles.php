<?php
require_once __DIR__ . '/../config/config.php';
requireAdmin();
$pageTitle = 'บทความ';
$pdo = getDBConnection();
$uid = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCsrf('articles.php');
    $id = (int) ($_POST['article_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    if ($title === '' || $content === '') {
        flash('error', 'กรุณากรอกหัวข้อและเนื้อหา');
    } elseif ($id > 0) {
        $pdo->prepare('UPDATE articles SET title=?, content=? WHERE article_id=?')->execute([$title, $content, $id]);
        flash('success', 'แก้ไขบทความสำเร็จ');
    } else {
        $pdo->prepare('INSERT INTO articles (title, content, created_by) VALUES (?,?,?)')->execute([$title, $content, $uid]);
        flash('success', 'เพิ่มบทความสำเร็จ');
    }
    redirect('articles.php');
}

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM articles WHERE article_id=?')->execute([(int) $_GET['delete']]);
    flash('success', 'ลบบทความสำเร็จ');
    redirect('articles.php');
}

$articles = $pdo->query('SELECT * FROM articles ORDER BY created_at DESC')->fetchAll();
require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar_admin.php'; ?>
  <main class="main-content">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="d-flex justify-content-end mb-3">
      <button class="btn btn-primary-app" data-bs-toggle="modal" data-bs-target="#artModal" onclick="openArtModal()"><i class="bi bi-plus-circle me-1"></i> เพิ่มบทความ</button>
    </div>
    <div class="app-card p-3">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>หัวข้อ</th><th>เนื้อหาย่อ</th><th>วันที่</th><th class="text-end">จัดการ</th></tr></thead>
          <tbody>
            <?php foreach ($articles as $a): ?>
              <tr>
                <td class="fw-semibold"><?= e($a['title']) ?></td>
                <td class="small text-muted"><?= e(mb_substr($a['content'], 0, 60)) ?>...</td>
                <td class="small"><?= formatDateThai($a['created_at']) ?></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-app" data-bs-toggle="modal" data-bs-target="#artModal" onclick='openArtModal(<?= json_encode($a, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                  <a class="btn btn-sm btn-outline-danger" href="?delete=<?= $a['article_id'] ?>" onclick="return confirm('ยืนยันการลบบทความนี้?')"><i class="bi bi-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<div class="modal fade" id="artModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;">
      <form method="post">
        <?= csrfField() ?>
        <div class="modal-header"><h5 class="modal-title" id="artModalTitle">เพิ่มบทความ</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="article_id" id="a_article_id" value="">
          <div class="mb-3"><label class="form-label">หัวข้อ</label><input type="text" name="title" id="a_title" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">เนื้อหา</label><textarea name="content" id="a_content" class="form-control" rows="8" required></textarea></div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary-app w-100">บันทึกบทความ</button></div>
      </form>
    </div>
  </div>
</div>

<script>
function openArtModal(data) {
  document.getElementById('artModalTitle').innerText = data ? 'แก้ไขบทความ' : 'เพิ่มบทความ';
  document.getElementById('a_article_id').value = data ? data.article_id : '';
  document.getElementById('a_title').value = data ? data.title : '';
  document.getElementById('a_content').value = data ? data.content : '';
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
