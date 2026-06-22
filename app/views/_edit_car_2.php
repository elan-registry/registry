<!-- Comments -->
<div class='mb-3 row'>
    <label for='comments' class='col-md-3 col-12 col-form-label'>Comments</label>
    <div class='col-12 col-sm-9'>
        <div class='input-group'>
            <span class='input-group-text'><i aria-hidden='true' class='fas fa-comment-alt'></i></span>
            <textarea class='form-control' name='comments' id='comments' rows='4' wrap='soft' maxlength='2000' placeholder='<?= htmlspecialchars($carprompt['comments'], ENT_QUOTES, 'UTF-8') ?>'><?= htmlspecialchars((string)($cardetails['comments'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <small class='form-text text-muted text-end d-block'><span id='comments-count'>0</span> / 2000 characters</small>
    </div>
</div>

<!-- Purchase Date  -->
<div class='mb-3 row'>
    <label for='purchasedate' class='col-md-3 col-12 col-form-label'>Purchase Date</label>
    <div class='col-12 col-sm-9'>
        <div class='input-group'>
            <span class='input-group-text'><i aria-hidden='true' class='fas fa-calendar'></i></span>
            <input class='form-control' name='purchasedate' id='purchasedate' value='<?= htmlspecialchars((string)($cardetails['purchasedate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>' type='text' placeholder='YYYY-MM-DD' pattern='\d{4}-\d{2}-\d{2}' inputmode='numeric' />
        </div>
        <small id='purchasedateHelp' class='form-text text-muted'>Approximate date you purchased the car. Use <strong>YYYY-MM-DD</strong> format (e.g. 1973-06-15).</small>
    </div>
</div>

<!-- Sold Date toggle -->
<div class='mb-3 row'>
    <div class='col-md-3 col-12'></div>
    <div class='col-12 col-sm-9'>
        <div class='form-check'>
            <input class='form-check-input' type='checkbox' id='sold-toggle' <?= !empty($cardetails['solddate']) ? 'checked' : '' ?> />
            <label class='form-check-label' for='sold-toggle'>I no longer own this car</label>
        </div>
    </div>
</div>

<!-- Sold Date (revealed by toggle) -->
<div id='solddate-row' class='mb-3 row <?= empty($cardetails['solddate']) ? 'd-none' : '' ?>'>
    <label for='solddate' class='col-md-3 col-12 col-form-label'>Sold Date</label>
    <div class='col-12 col-sm-9'>
        <div class='input-group'>
            <span class='input-group-text'><i aria-hidden='true' class='fas fa-calendar'></i></span>
            <input class='form-control' name='solddate' id='solddate' value='<?= htmlspecialchars((string)($cardetails['solddate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>' type='text' placeholder='YYYY-MM-DD' pattern='\d{4}-\d{2}-\d{2}' inputmode='numeric' />
        </div>
        <small id='solddateHelp' class='form-text text-muted'>Approximate date you sold the car. Use <strong>YYYY-MM-DD</strong> format (e.g. 1985-03-01).</small>
    </div>
</div>

<!-- Website -->
<div class='mb-3 row'>
    <label for='website' class='col-md-3 col-12 col-form-label'>Website</label>
    <div class='col-12 col-sm-9'>
        <div class='input-group'>
            <span class='input-group-text'><i aria-hidden='true' class='fas fa-palette'></i></span>
            <input class='form-control' type='url' name='website' id='website' placeholder='<?= htmlspecialchars($carprompt['website'], ENT_QUOTES, 'UTF-8') ?>' value='<?= htmlspecialchars((string)($cardetails['website'] ?? ''), ENT_QUOTES, 'UTF-8') ?>' />
        </div>
    </div>
</div>
