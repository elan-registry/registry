<div class='row'>
    <div class='col-md-3 col-xs-12 '>
        <ul>
            <li>Maximum of <?= $settings->elan_image_max ?> photos</li>
            <li>Maximum size <?= isset($settings->elan_image_upload_max_size) ? $settings->elan_image_upload_max_size : 2 ?> MB each</li>
            <li>Photos only</li>
            <li>Drag and Drop to reorder the photos</li>
        </ul>
    </div>
    <div class='col-md-9 col-xs-12'>
        <div class='dropzone dz-clickable' id='myDrop'>
            <div class='dz-default dz-message' data-dz-message=''>
                <span>Drop files here to upload</span>
            </div>
        </div>
    </div>


</div>