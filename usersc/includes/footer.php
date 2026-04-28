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
?>

<!-- ElanRegistry API Client - Pattern A standardized AJAX -->
<script nonce="<?=htmlspecialchars($usespice_nonce ?? '')?>" src="<?=$us_url_root?>app/assets/js/api-client.js"></script>

<!-- Patch: users/js/menu.js offClick handler (line 100) calls open.firstChild.click() where
     firstChild is a whitespace text node in indented HTML. Text nodes have no .click() method,
     throwing TypeError. Fix: in capture phase (before offClick fires), remove the .open class
     so that e.target.closest(".dropdown.open") inside offClick returns null, bypassing the
     broken firstChild.click() path entirely.
     Tracked in: https://github.com/unibrain1/elanregistry/issues/729 -->
<script nonce="<?=htmlspecialchars($usespice_nonce ?? '')?>">
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
