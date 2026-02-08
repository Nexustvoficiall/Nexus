<?php
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

include('includes/header.php');

// Normalize MAC address: decode, strip, format to lowercase colon-separated hex
function normalise_mac(string $raw): string {
    $dec = base64_decode($raw, true);
    if ($dec !== false) $raw = $dec;
    $raw = strtolower(trim($raw));
    if (strpos($raw, '00:') === 0) $raw = substr($raw, 3);
    $hex = preg_replace('/[^0-9a-f]/', '', $raw);
    $pairs = array_filter(str_split($hex, 2), static fn($p) => strlen($p) === 2);
    return implode(':', $pairs); // keep lowercase
}

$table_name = "playlist";
$res = $db->select($table_name, '*', '', '');

if (isset($_POST['submit'])) {
    unset($_POST['submit']);

    // Normalize MAC address before insert
    if (isset($_POST['mac_address'])) {
        $_POST['mac_address'] = normalise_mac($_POST['mac_address']);
    }

    // Generate device key if not provided
    if (empty($_POST['device_key'])) {
        $chars = '1234567890';
        $deviceKey = '';
        for ($i = 0; $i < 8; $i++) {
            $deviceKey .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $_POST['device_key'] = $deviceKey;
    }

    $db->insert($table_name, $_POST);
    $db->close();
    echo "<script>window.location.href='" . basename($_SERVER["SCRIPT_NAME"]) . "?status=1'</script>";
}

@$resU = $db->select($table_name, '*', 'id = :id', '', [':id' => $_GET['update']]);

if (isset($_POST['submitU'])) {
    unset($_POST['submitU']);
    $updateData = $_POST;

    // Normalize incoming MAC address (if present)
    if (isset($updateData['mac_address'])) {
        $updateData['mac_address'] = normalise_mac($updateData['mac_address']);
    }

    // Get current MAC address from DB for matching
    $current = $db->select($table_name, '*', 'id = :id', '', [':id' => $_GET['update']]);
    $mac = isset($current[0]['mac_address']) ? normalise_mac($current[0]['mac_address']) : '';

    // Update all rows with the same MAC's PIN
    if (!empty($mac) && isset($updateData['pin'])) {
        $db->update($table_name, ['pin' => $updateData['pin']], 'LOWER(mac_address) = :mac', [':mac' => strtolower($mac)]);
    }

    // Update remaining fields for this record only
    unset($updateData['pin']);
    $db->update($table_name, $updateData, 'id = :id', [':id' => $_GET['update']]);

    echo "<script>window.location.href='" . basename($_SERVER["SCRIPT_NAME"]) . "?status=1'</script>";
}

if (isset($_GET['delete'])) {
    $db->delete($table_name, 'id = :id', [':id' => $_GET['delete']]);
    echo "<script>window.location.href='" . basename($_SERVER["SCRIPT_NAME"]) . "?status=2'</script>";
}

$dnss = $db->select('dns', '*', '', '');
$dnsTitles = [];
foreach ($dnss as $dns) {
    $dnsTitles[$dns['id']] = $dns['title'];
}
?>
<div class="modal fade" id="confirm-delete" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Confirm</h2>
            </div>
            <div class="modal-body">
                Do you really want to delete?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <a class="btn btn-danger btn-ok">Delete</a>
            </div>
        </div>
    </div>
</div>
<?php if (isset($_GET['create'])){ ?>
        <div class="col-md-8 mx-auto">
            <div class="card-body">
                <div class="card bg-primary text-white">
                    <div class="card-header card-header-warning">
                        <center><h2><i class="icon icon-bullhorn"></i> Add User</h2></center>
                    </div>
                    <div class="card-body">
                        <div class="col-12"><h3>Add User</h3></div>
                        <form method="post">
                            <div class="form-group">
                                <label class="form-label " for="title">DNS</label>
                                <select class="form-control" name="dns_id">
                                    <option selected="selected">Choose one</option>
                                    <?php foreach($dnss as $dns) { ?>
                                    <option value="<?=$dns['id']?>"><?=$dns['title'] ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label " for="dns">MAC Address (will auto format)</label>
                                <input class="form-control" id="mac" name="mac_address" placeholder="MAC Address" type="text"/>
                            </div>
                            <script>
                                document.getElementById("mac").addEventListener('keyup', function() {
                                    this.value = (this.value.toUpperCase().replace(/[^\d|A-Z]/g, '').match(/.{1,2}/g) || []).join(":")
                                });
                            </script>
                            <div class="form-group">
                                <label class="form-label " for="title">Username</label>
                                <input class="form-control" id="description" name="username" placeholder="Username" type="text"/>
                            </div>
                            <div class="form-group">
                                <label class="form-label " for="dns">Password</label>
                                <input class="form-control" id="description" name="password" placeholder="Password" type="text"/>
                            </div>
                            <div class="form-group">
                                <label class="form-label " for="dns">Parental Pin</label>
                                <input class="form-control" id="description" name="pin" placeholder="Parental Pin" type="text" value="0000"/>
                            </div>
                            <input type="hidden" name="device_key" value="">
                            <div class="form-group">
                                <center><button class="btn btn-info" name="submit" type="submit"><i class="icon icon-check"></i> Submit</button></center>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
<?php } else if (isset($_GET['update'])){ ?>
        <div class="col-md-8 mx-auto">
            <div class="card-body">
                <div class="card bg-primary text-white">
                    <div class="card-header card-header-warning">
                        <center><h2><i class="icon icon-bullhorn"></i> Edit Current User</h2></center>
                    </div>
                    <div class="card-body">
                        <div class="col-12"><h3>Edit User</h3></div>
                        <form method="post">
                            <div class="form-group">
                                <label class="form-label" for="dns_id">DNS</label>
                                <select class="form-control" name="dns_id">
                                    <?php foreach($dnss as $dns) {
                                        $selected = ($dns['id'] == $resU[0]['dns_id']) ? 'selected' : '';
                                        echo "<option value='{$dns['id']}' {$selected}>{$dns['title']}</option>";
                                    } ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label " for="dns">MAC Address</label>
                                <input class="form-control" id="description" name="mac_address" value="<?=$resU[0]['mac_address'] ?>" type="text"/>
                            </div>
                            <div class="form-group">
                                <label class="form-label " for="title">Username</label>
                                <input class="form-control" id="description" name="username" value="<?=$resU[0]['username'] ?>" type="text"/>
                            </div>
                            <div class="form-group">
                                <label class="form-label " for="dns">Password</label>
                                <input class="form-control" id="description" name="password" value="<?=$resU[0]['password'] ?>" type="text"/>
                            </div>
                            <div class="form-group">
                                <label class="form-label " for="dns">Parental Pin</label>
                                <input class="form-control" id="description" name="pin" value="<?=$resU[0]['pin'] ?>" type="text"/>
                            </div>
                            <div class="form-group">
                                <center><button class="btn btn-info" name="submitU" type="submit"><i class="icon icon-check"></i> Submit</button></center>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
<?php } else { ?>
        <div class="col-md-12 mx-auto">
            <div class="card-body">
                <div class="card bg-primary text-white">
                    <div class="card-header card-header-warning">
                        <center><h2><i class="icon icon-commenting"></i> Current Users</h2></center>
                    </div>
                    <div class="card-body">
                        <div class="col-12">
                            <center><a id="button" href="./<?=basename($_SERVER["SCRIPT_NAME"]) ?>?create" class="btn btn-info">Create User</a></center>
                        </div>
                        <br>
                        <div class="table-responsive">
                            <input class="form-control" type="text" id="search" onkeyup="func2()" placeholder="Type to search">
                            <table id="users" class="table table-striped table-sm">
                                <thead style="color:white!important">
                                    <tr class="header">
                                        <th>DNS</th>
                                        <th>MAC Address</th>
                                        <th>Username</th>
                                        <th>Password</th>
                                        <th>Parental Pin</th>
                                        <th>Device Key</th>
                                        <th>Edit&nbsp&nbsp&nbspDelete</th>
                                    </tr>
                                </thead>
                                <?php foreach ($res as $row) {?>
                                <tbody>
                                    <tr>
                                        <td><?= $dnsTitles[$row['dns_id']] ?? 'Unknown DNS' ?></td>
                                        <td><?=$row['mac_address'] ?></td>
                                        <td><?=$row['username'] ?></td>
                                        <td><?=$row['password'] ?></td>
                                        <td><?= !empty($row['pin']) ? $row['pin'] : '0000' ?></td>
                                        <td><?=$row['device_key'] ?></td>
                                        <td>
                                            <a class="btn btn-info btn-ok" href="./<?=basename($_SERVER["SCRIPT_NAME"]) ?>?update=<?=$row['id'] ?>"><i class="fa fa-pencil-square-o"></i></a>
                                            &nbsp&nbsp&nbsp
                                            <a class="btn btn-danger btn-ok" href="#" data-href="./<?=basename($_SERVER["SCRIPT_NAME"]) ?>?delete=<?=$row['id'] ?>" data-toggle="modal" data-target="#confirm-delete"><i class="fa fa-trash-o"></i></a>
                                        </td>
                                    </tr>
                                </tbody>
                                <?php }?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<?php } ?>

<?php include ('includes/footer.php');?>
</body>
</html>
