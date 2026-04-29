<div class='row'>
    <div class='col-12'>
        <ul>
            <li>Maximum of <?= $settings->elan_image_max ?> photos</li>
            <li>Maximum size <?= isset($settings->elan_image_upload_max_size) ? $settings->elan_image_upload_max_size : 2 ?> MB each</li>
            <li>Photos only</li>
            <li>Tap and hold to reorder &bull; Drag on desktop</li>
        </ul>
        <input type="file" id="myPond" multiple accept="image/*">
    </div>
</div>
