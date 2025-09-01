        <!-- Car Info -->
        <?php
        if (isset($cardetails['id'])) {
        ?>

            <div class="form-group row">
                <label for="carlabel" class="col-md-3 col-xs-12  col-form-label">Car ID</label>
                <div class="col-sm-1">
                    <div id='carlabel' class='input-group-prepend'><?= $cardetails['id'] ?></div>
                </div>
            </div>
        <?php
        }
        ?>
        <!-- Year -->
        <div class="form-group row">
            <label for="year" class="col-md-3 col-xs-12 col-form-label">Year *</label>
            <div class="col-sm-9">
                <div class="input-group-prepend">
                    <div class="input-group-text"><i aria-hidden="true" class="fas fa-calendar-check"></i> </div>
                    <select name='year' id='year' class='custom-select form-control'>
                        <option value="">--Choose Year--</option>
                        <option value="1963">1963</option>
                        <option value="1964">1964</option>
                        <option value="1965">1965</option>
                        <option value="1966">1966</option>
                        <option value="1967">1967</option>
                        <option value="1968">1968</option>
                        <option value="1969">1969</option>
                        <option value="1970">1970</option>
                        <option value="1971">1971</option>
                        <option value="1972">1972</option>
                        <option value="1973">1973</option>
                        <option value="1974">1974</option>
                    </select>
                    <div class='input-group-text'><i id="year_icon" aria-hidden='true' class="fas fa-thumbs-down"></i></div>
                </div>
            </div>
        </div>

        <!-- Model -->
        <div class="form-group row">
            <label for="model" class="col-md-3 col-xs-12  col-form-label">Model *</label>
            <div class="col-sm-9">
                <div class="input-group-prepend">
                    <div class="input-group-text"><i aria-hidden="true" class="fas fa-car-side"></i></div>
                    <select disabled class="form-control custom-select" name="model" id="model">
                        <option value="">--Please Select Model--</option>
                    </select>
                    <div class='input-group-text'><i id="model_icon" aria-hidden='true' class="fas fa-thumbs-down "></i></div>
                </div>
            </div>
        </div>


        <!-- Chassis -->
        <div class="form-group row">
            <label for="chassis" class="col-md-3 col-xs-12  col-form-label">Chassis *</label>
            <div class="col-sm-9">
                <div class="input-group-prepend">
                    <div class="input-group-text"><i aria-hidden="true" class="fas fa-barcode"></i></div>
                    <input data-lpignore="true" disabled class="form-control" type="text" name="chassis" id="chassis" placeholder="<?= $carprompt['chassis'] ?>" value="<?= $cardetails['chassis'] ?>" />
                    <div class='input-group-text'><i id="chassis_icon" aria-hidden='true' class="fas fa-thumbs-down "></i></div>
                </div>


                <div id="chassis_taken" class="text-danger hidden">
                    <strong>This chassis number is already in the registry. </strong>
                    <br> Please contact the Registrar<br>
                </div>
                <div id="chassis_pre1970" class="hidden">
                    <strong>Before 1970</strong><br>The chassis number should be 4 digits. Do not enter the type (i.e. 26/0001 enter 0001)<br>
                </div>
                <div id="chassis_1970" class="hidden">
                    <strong>1970</strong><br>The chassis can have two forms<br>
                    <ul>
                        <li>4 Digits plus letter - Do not enter the type (i.e. 26/0001x enter 0001x)</li>
                        <li>11 digits starting with the Year (i.e. YYmmbbssssT)</li>
                        <ul>
                            <li>YY = 2 digit year</li>
                            <li>mm = month</li>
                            <li>bb = batch numner</li>
                            <li>uuuu = unit number</li>
                            <li>T = Type Letter</li>
                        </ul>
                    </ul>
                </div>
                <div id="chassis_post1970" class="hidden">
                    <strong>After 1970</strong><br>The Chassis number is 11 digits starting with the Year (i.e. YYmmbbssssT)<br>
                    <ul>
                        <li>YY = 2 digit year</li>
                        <li>mm = month</li>
                        <li>bb = batch numner</li>
                        <li>uuuu = unit number</li>
                        <li>T = Type Letter</li>
                    </ul>
                </div>
                
                <div id="chassis_validation_error" class="text-danger hidden">
                    <strong>Chassis Validation Failed:</strong><br>
                    <span id="chassis_error_reason"></span>
                </div>
                
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" id="chassis_override" name="chassis_override" value="1">
                    <label class="form-check-label text-warning" for="chassis_override">
                        <strong>⚠️ Override chassis validation</strong><br>
                        <small>Check this box to proceed with an invalid chassis number. Use with caution - this may indicate incorrect data entry.</small>
                    </label>
                </div>
            </div>
        </div>

        <!-- Color -->
        <div class="form-group row">
            <label for="color" class="col-md-3 col-xs-12  col-form-label">Color</label>
            <div class="col-sm-9">
                <div class="input-group-prepend">
                    <div class="input-group-text"><i aria-hidden="true" class="fas fa-palette"></i></div>
                    <input class="form-control" type="text" name="color" id="color" placeholder="<?= $carprompt['color'] ?>" value="<?= $cardetails['color'] ?>" />
                </div>
            </div>
        </div>

        <!-- Engine Number -->
        <div class="form-group row">
            <label for="engine" class="col-md-3 col-xs-12  col-form-label">Engine Number</label>
            <div class="col-sm-9">
                <div class="input-group-prepend">
                    <div class="input-group-text"><i aria-hidden="true" class="fas fa-car"></i> </div>
                    <input class="form-control" type="text" name="engine" id="engine" placeholder="<?= $carprompt['engine'] ?>" value="<?= $cardetails['engine'] ?>" /> <!-- Add validation -->
                </div>
            </div>
        </div>