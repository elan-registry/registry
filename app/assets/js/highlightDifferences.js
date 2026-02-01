/**
 * highlightDifferences.js
 * Highlights cell differences between consecutive rows in the car history table.
 * Called from car_details.js on DataTables draw event.
 */
function highlightDifferences() {
  const $rows = $("#carHistoryTable tbody tr");

  $rows.each(function (index) {
    // Skip baseline row (last/oldest)
    if (index === $rows.length - 1) {
      return;
    }

    const $currentRow = $(this);
    const $prevRow = $rows.eq(index + 1);
    const currentCells = $currentRow.find("td");
    const prevCells = $prevRow.find("td");

    // Compare each cell (skip Operation at index 0 and Date Modified at index 1)
    currentCells.each(function (cellIndex) {
      if (cellIndex <= 1) return;

      const currentVal = $(this).text().trim();
      const prevVal = prevCells.eq(cellIndex).text().trim();

      if (currentVal === prevVal) return;

      // Remove any previous highlight classes before applying new ones
      $(this).removeClass("table-success table-danger table-info");

      if (currentVal !== "" && prevVal === "") {
        $(this).addClass("table-success");
      } else if (currentVal === "" && prevVal !== "") {
        $(this).addClass("table-danger");
      } else if (currentVal !== "" && prevVal !== "") {
        $(this).addClass("table-info");
      }
    });
  });
}
