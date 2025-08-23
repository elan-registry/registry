<div class="content mt-3">
    <!-- Site Settings -->
    <form class="" action="admin.php?tab=7" method="post" name="custom_settings">
        <h2 class="mb-3">Elan Regsitry Settings</h2>
        <div class="row">
            <div class="col-md-6">
                <!-- Left -->
                <div class="card no-padding">
                    <div class="card-header">
                        <h3>API Keys</h3>
                    </div>
                    <div class="card-body">
                        <!-- Site Name -->
                        <div class="form-group">
                            <label for='elan_google_maps_key'>Google Maps API Key</label> Give URL
                            <input class="form-control ajxtxt" data-desc="Google Maps API Key" name="elan_google_maps_key" id="elan_google_maps_key" value="<?= $settings->elan_google_maps_key; ?>">
                        </div>
                        <div class="form-group">
                            <label>Google Geoencode Key</label> Give URL
                            <input class="form-control ajxtxt" data-desc="Google Geoencode Key" name="elan_google_geo_key" id="elan_google_geo_key" value="<?= $settings->elan_google_geo_key; ?>">
                        </div>
                    </div>
                </div>

                <div class='card no-padding'>
                    <div class='card-header'>
                        <h3>Backup Settings</h3>
                    </div>
                    <div class='card-body'>
                        <div class='form-group'>
                            <label for='elan_backup_age'>Backup Age (Days)</label>
                            <input type="number" step="1" min="1" class="form-control ajxnum" data-desc="Backup Age" name="elan_backup_age" id="elan_backup_age" value="<?= $settings->elan_backup_age; ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card no-padding">
                    <div class="card-header">
                        <h3>Image Settings</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>Image Upload Directory</label>
                            <input class="form-control ajxtxt" data-desc="Image Upload Directory" name="elan_image_dir" id="elan_image_dir" value="<?= $settings->elan_image_dir; ?>">
                        </div>
                        <div class="form-group">
                            <label for='elan_image_max'>Max Photo Upload</label>
                            <input type="number" step="1" min="1" class="form-control ajxnum" data-desc="Max Photo Upload" name="elan_image_max" id="elan_image_max" value="<?= $settings->elan_image_max; ?>">
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class=" row">
            <div class="col-md-12">
                <div class="card no-padding">
                    <div class="card-header">
                        <h3>JQuery UI CDN</h3>
                    </div>
                    <div class="card-body">
                        <a style='text-decoration: underline;' rel="noopener noreferrer" target='_blank' href='https://jqueryui.com'>jQuery UI</a><br>
                        <small class="form-text text-muted">Used by car edit forms for enhanced date picker functionality.</small>
                        <div class="form-group">
                            <label for='elan_jquery_ui_cdn'>JQuery UI CDN URL</label>
                            <textarea rows="4" class="form-control ajxtxt" data-desc="JQuery UI CDN URL" name="elan_jquery_ui_cdn" id="elan_jquery_ui_cdn"><?= $settings->elan_jquery_ui_cdn; ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="card no-padding">
                    <div class="card-header">
                        <h3>Datatables</h3>
                    </div>
                    <div class="card-body">
                        <a style='text-decoration: underline;' rel="noopener noreferrer" target='_blank' href='https://datatables.net/download/'>Get Datatables</a>
                        <small id="fileHelp" class="form-text text-muted">Options: Bootstrap 4 styling, Datatables package Extenstions: fixedHeader,Responsive, RowGroup, Scroller,SearchBuilder,SearchPanes,Minify, Concatenate </small>
                        <div class="form-group">
                            <label for='elan_datatables_js_cdn'>Datatables JS CDN URL</label>
                            <input type="text" class="form-control ajxtxt" data-desc="Datatables JS CDN URL" name="elan_datatables_js_cdn" id="elan_datatables_js_cdn" value="<?= $settings->elan_datatables_js_cdn; ?>">
                        </div>
                        <div class="form-group">
                            <label for='elan_datatables_css_cdn'>Datatables CSS CDN URL</label>
                            <input type="text" class="form-control ajxtxt" data-desc="Datatables CSS CDN URL" name="elan_datatables_css_cdn" id="elan_datatables_css_cdn" value="<?= $settings->elan_datatables_css_cdn; ?>">
                        </div>
                    </div>
                </div>

                <div class="card no-padding">
                    <div class="card-header">
                        <h3>Date Picker</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <a style='text-decoration: underline;' rel="noopener noreferrer" target='_blank' href='https://cdnjs.com/libraries/bootstrap-datepicker'>Get Bootstrap Datepicker CDN</a><br>
                            <label for='elan_datepicker_js_cdn'>Datepicker JS CDN URL</label>
                            <input type="text" class="form-control ajxtxt" data-desc="Datepicker JS CDN URL" name="elan_datepicker_js_cdn" id="elan_datepicker_js_cdn" value="<?= $settings->elan_datepicker_js_cdn; ?>">
                        </div>
                        <div class="form-group">
                            <label for='elan_datepicker_css_cdn'>Datepicker CSS CDN URL</label>
                            <input type="text" class="form-control ajxtxt" data-desc="Datepicker CSS CDN URL" name="elan_datepicker_css_cdn" id="elan_datepicker_css_cdn" value="<?= $settings->elan_datepicker_css_cdn; ?>">
                        </div>
                    </div>
                </div>

                <div class="card no-padding">
                    <div class="card-header">
                        <h3>Dropzone.js</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <a style='text-decoration: underline;' rel="noopener noreferrer" target='_blank' href='https://dropzone.js'>Get Dropzone</a><br>

                            <label for='elan_dropzone_js_cdn'>Dropzone JS CDN URL</label>
                            <input type="text" class="form-control ajxtxt" data-desc="Dropzone JS CDN URL" name="elan_dropzone_js_cdn" id="elan_dropzone_js_cdn" value="<?= $settings->elan_dropzone_js_cdn; ?>">
                        </div>
                        <div class="form-group">
                            <label for='elan_dropzone_css_cdn'>Dropzone CSS CDN URL</label>
                            <input type="text" class="form-control ajxtxt" data-desc="Dropzone CSS CDN URL" name="elan_dropzone_css_cdn" id="elan_dropzone_css_cdn" value="<?= $settings->elan_dropzone_css_cdn; ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
