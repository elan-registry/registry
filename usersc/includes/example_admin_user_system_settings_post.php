<?php require_once '../users/init.php'; ?>
<?php if (!securePage($_SERVER['PHP_SELF'])){die();} ?>
<?php  //You can use this file to add a "System Settings" button to the user administration page and add any settings you want in there. Run our PHP (post) in here. ?>
<?php //If you edit directly from this file don't forget  to rename it to remove the "example_" so it will be detected. ?>
<?php if(!empty($_POST)) {
  $whatismyname = ucfirst(Input::get("whatismyname"));
  if ($userdetails->whatismyname != $whatismyname) {

    $fields=array('whatismyname'=>$whatismyname);
    $validation->check($_POST,array(
      'whatismyname' => array(
        'display' => 'What Is My Name',
        'required' => true,
        'min' => 1,
        'max' => 255
      )
    ));
  if($validation->passed()){
    $db->update('users',$userId,$fields);
    $successes[] = "First Name Updated";
    logger($user->data()->id,LogCategories::LOG_CATEGORY_USER_MANAGER,"Updated whatismyname for $userdetails->whatismyname from $userdetails->whatismyname to $whatismyname.");
  }else{
    // Convert validation errors to UserSpice session messages (Issue #237)
    if (!empty($validation->errors())) {
      foreach ($validation->errors() as $error) {
        usError($error);
      }
    }
    }
  }
  
  // Display session messages (Issue #237)
  sessionValMessages($errors ?? [], $successes ?? [], null);
      } ?>
