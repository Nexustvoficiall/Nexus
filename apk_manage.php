<?php
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

include ('includes/header.php');

$table_name = "apk_update";

// Get current value
$res = $db->select($table_name, '*', '', 'id DESC LIMIT 1');

// Handle insert/update
if (isset($_POST['submit'])) {
    unset($_POST['submit']);

    if (empty($res)) {
        $db->insert($table_name, $_POST);
    } else {
        $db->update($table_name, $_POST, 'id = :id', [':id' => $res[0]['id']]);
    }

    echo "<script>window.location.href='" . basename($_SERVER["SCRIPT_NAME"]) . "?status=1'</script>";
}
?>

<div class="col-md-8 mx-auto">
    <div class="card-body">
        <div class="card bg-primary text-white">
            <div class="card-header card-header-warning">
                <center>
                    <h2><i class="icon icon-android"></i> APK Version Manager</h2>
                </center>
            </div>

            <div class="card-body">
                <div class="col-12">
                    <h3>Update APK Version</h3>
                </div>
                <form method="post">
                    <div class="form-group">
                        <label class="form-label" for="version">App Version</label>
                        <input class="form-control" name="version" type="text" placeholder="e.g. 1.20"
                            value="<?= $res[0]['version'] ?? '' ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="apk_url">APK URL</label>
                        <input class="form-control" name="apk_url" type="text" placeholder="https://example.com/app.apk"
                            value="<?= $res[0]['apk_url'] ?? '' ?>">
                    </div>
                    <div class="form-group">
                        <center>
                            <button class="btn btn-info" name="submit" type="submit">
                                <i class="icon icon-check"></i> Save
                            </button>
                        </center>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include('includes/footer.php'); ?>
</body>
</html>
