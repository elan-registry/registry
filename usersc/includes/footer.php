<?php
/*
UserSpice 4
An Open Source PHP User Management System
by the UserSpice Team at http://UserSpice.com

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

//This will go in every template.
require_once $abs_us_root . $us_url_root . 'app/version.php';
$er_footer_version_tag = ApplicationVersion::tagOnly();
?>

<!-- Privacy Policy and Contact Us links injected into footer without modifying upstream template -->
<script nonce="<?=htmlspecialchars($userspice_nonce ?? '')?>">
(function () {
    var container = document.querySelector('#footer .container');
    if (container) {
        var p = document.createElement('p');
        p.className = 'text-center small';
        var aPrivacy = document.createElement('a');
        aPrivacy.href = '<?=$us_url_root?>app/owner/privacy.php';
        aPrivacy.className = 'text-muted';
        aPrivacy.textContent = 'Privacy Policy';
        p.appendChild(aPrivacy);
        <?php if (isset($user) && $user->isLoggedIn()) { ?>
        p.appendChild(document.createTextNode(' | '));
        var aContact = document.createElement('a');
        aContact.href = '<?=$us_url_root?>app/owner/contact/index.php';
        aContact.className = 'text-muted';
        aContact.textContent = 'Contact Us';
        p.appendChild(aContact);
        <?php } ?>
        p.appendChild(document.createTextNode(' | '));
        var verSpan = document.createElement('span');
        verSpan.className = 'text-muted';
        verSpan.textContent = '<?=htmlspecialchars($er_footer_version_tag, ENT_QUOTES, 'UTF-8')?>';
        p.appendChild(verSpan);
        container.appendChild(p);
    }
}());
</script>

<!-- ElanRegistry API Client - Pattern A standardized AJAX -->
<script nonce="<?=htmlspecialchars($userspice_nonce ?? '')?>" src="<?=$us_url_root?>app/assets/js/api-client.min.js?v=<?= ASSET_VERSION ?>"></script>

<!-- Patch: users/js/menu.js offClick handler (line 100) calls open.firstChild.click() where
     firstChild is a whitespace text node in indented HTML. Text nodes have no .click() method,
     throwing TypeError. Fix: in capture phase (before offClick fires), remove the .open class
     so that e.target.closest(".dropdown.open") inside offClick returns null, bypassing the
     broken firstChild.click() path entirely.
     Tracked in: https://github.com/unibrain1/elanregistry/issues/729 -->
<script nonce="<?=htmlspecialchars($userspice_nonce ?? '')?>">
(function () {
    document.addEventListener('click', function (evt) {
        var openDropdown = document.querySelector('.us_menu .dropdown.open');
        if (!openDropdown) return;
        if (openDropdown.contains(evt.target)) return;
        var sub = openDropdown.querySelector('.us_sub-menu.show');
        if (sub) sub.classList.remove('show');
        openDropdown.classList.remove('open');
    }, true); // capture phase — runs before upstream bubbling offClick handler
}());
</script>
