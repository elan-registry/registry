<!-- Purchase Date  -->
<div class='form-group row'>
    <label for='purchasedate' class='col-md-3 col-xs-12  col-form-label'>Purchase Date</label>
    <div class='col-sm-9'>
        <div class='input-group-prepend'>
            <div class='input-group-text'> <i aria-hidden='true' class='fas fa-calendar'></i></div>
            <input class='form-control' name='purchasedate' id='purchasedate' placeholder='<?= $carprompt['purchasedate'] ?>' value='<?= $cardetails['purchasedate'] ?>' type='text' />
        </div>
        <small id='purchaseHelp' class='form-text text-muted'>Approximate date you purchased the car</small>
    </div>
</div>

<!-- Sold Date -->
<div class='form-group row'>
    <label for='solddate' class='col-md-3 col-xs-12  col-form-label'>Sold Date</label>
    <div class='col-sm-9'>
        <div class='input-group-prepend'>
            <div class='input-group-text'><i aria-hidden='true' class='fas fa-calendar'></i></div>
            <input class='form-control' name='solddate' id='solddate' placeholder='<?= $carprompt['solddate'] ?>' value='<?= $cardetails['solddate'] ?>' type='text' />
        </div>
        <small id='purchaseHelp' class='form-text text-muted'>If you no longer own the car, approximate date you sold the car</small>
    </div>
</div>
<!-- Website -->
<div class='form-group row'>
    <label for='website' class='col-md-3 col-xs-12  col-form-label'>Website</label>
    <div class='col-sm-9'>
        <div class='input-group-prepend'>
            <div class='input-group-text'><i aria-hidden='true' class='fas fa-palette'></i></div>
            <input class='form-control' type='url' name='website' id='website' placeholder='<?= $carprompt['website'] ?>' value='<?= $cardetails['website'] ?>' />
        </div>
    </div>
</div>
<div class='form-group row'>
    <label for='comments' class='col-md-3 col-xs-12  col-form-label'>Comments</label>
    <div class='col-sm-9'>
        <div class='input-group-prepend'>
            <div class='input-group-text'><i aria-hidden='true' class='fas fa-comment-alt'></i></div>
            <textarea class='form-control' name='comments' id='comments' rows='10' wrap='soft' placeholder='<?= $carprompt['comments'] ?>'><?= htmlspecialchars($cardetails['comments'] ?? ''); ?></textarea>
        </div>
    </div>

</div>