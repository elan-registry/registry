-- Issue #751: Rename app/cars/edit.php to app/cars/form.php
UPDATE `pages` SET `page` = 'app/cars/form.php' WHERE `page` = 'app/cars/edit.php';
