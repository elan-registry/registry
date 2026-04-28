<!-- Purchase Date  -->
<div class='mb-3 row'>
    <label for='purchasedate' class='col-md-3 col-xs-12  col-form-label'>Purchase Date</label>
    <div class='col-sm-9'>
        <div class='input-group'>
            <span class='input-group-text'><i aria-hidden='true' class='fas fa-calendar'></i></span>
            <input class='form-control' name='purchasedate' id='purchasedate' value='<?= $cardetails['purchasedate'] ?>' type='text' placeholder='YYYY-MM-DD' pattern='\d{4}-\d{2}-\d{2}' inputmode='numeric' />
        </div>
        <small id='purchasedateHelp' class='form-text text-muted'>Approximate date you purchased the car &mdash; use <strong>YYYY-MM-DD</strong> format (e.g. 1973-06-15).</small>
    </div>
</div>

<!-- Sold Date -->
<div class='mb-3 row'>
    <label for='solddate' class='col-md-3 col-xs-12  col-form-label'>Sold Date</label>
    <div class='col-sm-9'>
        <div class='input-group'>
            <span class='input-group-text'><i aria-hidden='true' class='fas fa-calendar'></i></span>
            <input class='form-control' name='solddate' id='solddate' value='<?= $cardetails['solddate'] ?>' type='text' placeholder='YYYY-MM-DD' pattern='\d{4}-\d{2}-\d{2}' inputmode='numeric' />
        </div>
        <small id='solddateHelp' class='form-text text-muted'>If you no longer own the car, approximate date you sold it &mdash; use <strong>YYYY-MM-DD</strong> format (e.g. 1985-03-01).</small>
    </div>
</div>
<!-- Website -->
<div class='mb-3 row'>
    <label for='website' class='col-md-3 col-xs-12  col-form-label'>Website</label>
    <div class='col-sm-9'>
        <div class='input-group'>
            <span class='input-group-text'><i aria-hidden='true' class='fas fa-palette'></i></span>
            <input class='form-control' type='url' name='website' id='website' placeholder='<?= $carprompt['website'] ?>' value='<?= $cardetails['website'] ?>' />
        </div>
    </div>
</div>
<div class='mb-3 row'>
    <label for='comments' class='col-md-3 col-xs-12  col-form-label'>Comments</label>
    <div class='col-sm-9'>
        <div class='input-group'>
            <span class='input-group-text'><i aria-hidden='true' class='fas fa-comment-alt'></i></span>
            <textarea class='form-control' name='comments' id='comments' rows='10' wrap='soft' placeholder='<?= $carprompt['comments'] ?>'><?= htmlspecialchars($cardetails['comments'] ?? ''); ?></textarea>
        </div>
    </div>

</div>