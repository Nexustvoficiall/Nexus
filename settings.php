<?php
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

include ('includes/header.php');

$table_name = 'settings';

$data = ['tmdb_key' => ''];
$db->insertIfEmpty($table_name, $data);

$res = $db->select($table_name, '*', '', '');

if(isset($_POST['submit'])){
	unset($_POST['submit']);
	$updateData = $_POST;
	$db->update($table_name, $updateData, 'id = :id',[':id' => 1]);
	echo "<script>window.location.href='". basename($_SERVER["SCRIPT_NAME"])."?status=1'</script>";
}

?>

        <div class="col-md-6 mx-auto">
            <div class="card-body">
                <div class="card bg-primary text-white">
                    <div class="card-header card-header-warning">
                        <center>
                            <h2><i class="icon icon-key"></i> Tmdb Settings</h2>
                        </center>
                    </div>
                    
                    <div class="card-body">
                            <form method="post">
                                <div class="form-group">
                                    <label class="form-label " >Tmdb API KEY</label>
                                        <input class="form-control"  name="tmdb_key" value="<?=$res[0]['tmdb_key'] ?>" type="text"/>
                                </div>

                                <div class="form-group">
                                    <center>
                                        <button class="btn btn-info " name="submit" type="submit">
                                            <i class="icon icon-check"></i> Submit
                                        </button>
                                    </center>
                                </div>
                            </form>
                    </div>
                </div>
            </div>
        </div>

<?php include ('includes/footer.php');?>