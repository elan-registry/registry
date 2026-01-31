  <script>
      $(document).ready(function () {
        highlightDifferences();
      });

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

          // Compare each cell (skip Date column at index 0)
          currentCells.each(function (cellIndex) {
            if (cellIndex === 0) return;

            const currentVal = $(this).text().trim();
            const prevVal = prevCells.eq(cellIndex).text().trim();

            if (currentVal === prevVal) return;

            if (currentVal !== "" && prevVal === "") {
              $(this).addClass("diff-new");
            } else if (currentVal === "" && prevVal !== "") {
              $(this).addClass("diff-deleted");
            } else if (currentVal !== "" && prevVal !== "") {
              $(this).addClass("diff-changed");
            }
          });
        });
      }
    </script>