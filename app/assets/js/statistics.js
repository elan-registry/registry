/* exported getColorForName, initializeOverviewTab, setupTabLazyLoading, loadTabContent, renderTabContent, renderGeographicTab, renderProductionTab, renderColorsTab, renderQualityTab, renderSeriesTable, updateOverviewMetrics, createTimelineChart, createRecentActivityChart, createCountryChart, createCountryDistributionChart, createUSStatesChart, createTypeChart, createSeriesChart, createVariantChart, createProductionYearChart, createEarlyLateChart, createColorDistributionChart, createColorByYearChart, createColorBySeriesChart, createDataCompletenessChart, statisticsInitMap, loadMapMarkers, destroyAllCharts */
/**
 * statistics.js
 * JavaScript functionality for the enhanced statistics page
 * Uses Chart.js for data visualization with Bootstrap theming and lazy loading
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */

// Global chart instances to manage cleanup
window.statisticsCharts = {};

// Elan Registry brand palette (issue #757 — mirrors --er-* tokens in customizer.css)
const BOOTSTRAP_COLORS = {
  primary:   "#00563F",  // BRG — bar charts
  secondary: "#6C757D",
  success:   "#00563F",  // unified with primary (no separate success green for charts)
  danger:    "#A52218",
  warning:   "#B8860B",
  info:      "#0B5394",  // ink blue — line charts
  light:     "#F4F5F3",
  dark:      "#1F2421"
};

// Categorical palette: Tableau 10 (entries 1–10) extended with two Tableau 20 colors (mauve, sage)
const CHART_COLORS = [
  "#4E79A7",  // steel blue
  "#F28E2B",  // orange
  "#E15759",  // red
  "#76B7B2",  // teal
  "#59A14F",  // green
  "#EDC948",  // yellow
  "#B07AA1",  // purple
  "#FF9DA7",  // pink
  "#9C755F",  // brown
  "#BAB0AC",  // grey
  "#D4A6C8",  // mauve
  "#86BCB6"   // sage
];

// Returns true when a hex color is too light to read on a white background.
// Uses ITU-R BT.601 perceived-luminance weights (range 0–255); threshold 200
// corresponds to approximately 78% brightness. Returns false (treat as dark)
// on invalid input so callers degrade gracefully with no spurious grey borders.
const isLightColor = (hex) => {
  if (typeof hex !== "string" || !/^#[0-9A-Fa-f]{6}$/.test(hex)) {
    console.error("[ElanRegistry] isLightColor: invalid hex value:", hex);
    return false;
  }
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return (r * 299 + g * 587 + b * 114) / 1000 > 200;
};

// Returns Chart.js border style for a color swatch: light colors get a grey
// outline so they remain visible on a white background; dark colors get
// borderWidth: 0 (the swatch color is preserved as borderColor but invisible).
const swatchBorder = (hex) => {
  const light = isLightColor(hex);
  return {
    borderColor: light ? "#BDBDBD" : hex,
    borderWidth: light ? 1 : 0
  };
};

// Maps known color variants to canonical family names ("carnival red" → "Red").
// Unrecognized names are title-cased and passed through as their own category,
// so they appear as separate chart slices rather than being silently dropped.
const normalizeColorName = (color) => {
  if (color == null || typeof color !== "string") {
    console.error("[ElanRegistry] normalizeColorName: received non-string value:", color);
    return "Unknown";
  }
  const lower = color.trim().toLowerCase().replace(/\s+/g, " ");
  if (["red", "carnival red", "red/white", "dark red"].includes(lower)) return "Red";
  if (["yellow", "lotus yellow", "bright yellow", "pale yellow", "yellow/white"].includes(lower)) return "Yellow";
  if (["white", "cirrus white", "pearl white", "off white"].includes(lower)) return "White";
  if (["blue", "french blue", "lagoon blue", "navy blue", "light blue", "dark blue"].includes(lower)) return "Blue";
  if (["green", "brg", "british racing green", "dark green", "light green"].includes(lower)) return "Green";
  if (lower === "black") return "Black";
  if (["silver", "grey", "gray"].includes(lower)) return "Silver";
  return lower.split(" ").map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(" ");
};

// Color mapping for realistic color representation
function getColorForName(colorName) {
  const name = colorName.toLowerCase().trim();

  // Define realistic color mappings
  const colorMap = {
    red: "#DC3545",
    "carnival red": "#DC3545",
    "dark red": "#DC3545",
    blue: "#007BFF",
    "dark blue": "#007BFF",
    "light blue": "#007BFF",
    "navy blue": "#007BFF",
    white: "#F8F9FA",
    "off white": "#FFFACD",
    "pearl white": "#F8F8F8",
    "cirrus white": "#E6E6FA",
    yellow: "#FFC107",
    "bright yellow": "#FFFF00",
    "pale yellow": "#FFFFE0",
    green: "#28A745",
    "dark green": "#006400",
    "light green": "#90EE90",
    "british racing green": "#004225",
    brg: "#004225",
    black: "#343A40",
    silver: "#C0C0C0",
    grey: "#6C757D",
    gray: "#6C757D",
    orange: "#FD7E14",
    purple: "#6F42C1",
    brown: "#8B4513",
    gold: "#FFD700",
    bronze: "#CD7F32",
    pink: "#E83E8C",
    maroon: "#800000"
  };

  // Try exact match first
  if (colorMap[name]) {
    return colorMap[name];
  }

  // Try partial matches for compound colors (e.g., "Dark Red" matches "red")
  for (const [key, value] of Object.entries(colorMap)) {
    if (name.includes(key)) {
      return value;
    }
  }

  // Smart fallback: try to guess color from common patterns
  if (name.includes("light") || name.includes("pale")) {
    return "#D3D3D3"; // Light gray for light variants
  }
  if (name.includes("dark") || name.includes("deep")) {
    return "#2C2C2C"; // Dark gray for dark variants
  }
  if (name.includes("metallic") || name.includes("pearl")) {
    return "#C0C0C0"; // Silver for metallic variants
  }

  // Consistent fallback colors for unknown colors (deterministic hash)
  const fallbackColors = [
    "#FF6384",
    "#36A2EB",
    "#FFCE56",
    "#4BC0C0",
    "#9966FF",
    "#FF9F40",
    "#C9CBCF",
    "#E74C3C",
    "#3498DB",
    "#F39C12",
    "#27AE60",
    "#8E44AD",
    "#E67E22",
    "#95A5A6"
  ];

  // Create deterministic hash for consistent color assignment
  let hash = 0;
  for (let i = 0; i < name.length; i++) {
    const char = name.charCodeAt(i);
    hash = (hash << 5) - hash + char;
    hash = hash & hash; // Convert to 32bit integer
  }

  // Warn when an unrecognized color name falls through to the deterministic hash fallback
  console.warn(`Unknown car color detected: "${colorName}" - using fallback color. Consider adding to color mapping.`);

  return fallbackColors[Math.abs(hash) % fallbackColors.length];
}

/**
 * Initialize the statistics dashboard
 */
$(document).ready(function () {
  try {
    // Initialize overview tab (load immediately)
    initializeOverviewTab();

    // Set up lazy loading for other tabs
    setupTabLazyLoading();

    // Update overview metrics
    updateOverviewMetrics();
  } catch (error) {
    console.error("Error in statistics initialization:", error);
  }
});

/**
 * Initialize Overview Tab with essential charts and the MapLibre map
 */
function initializeOverviewTab() {
  // Map initialization is handled by statisticsInitMap (called from statistics.php)
  try { createTimelineChart(); } catch (e) { console.error("[ElanRegistry] createTimelineChart failed:", e); }
  try { createRecentActivityChart(); } catch (e) { console.error("[ElanRegistry] createRecentActivityChart failed:", e); }
}

/**
 * Set up lazy loading for tab content
 */
function setupTabLazyLoading() {
  const loadedTabs = new Set(["overview"]); // Overview is already loaded

  $('#statisticsTabs a[data-bs-toggle="tab"]').on("shown.bs.tab", function (e) {
    const hrefAttr = $(e.target).attr("href") || $(e.target).attr("data-bs-target");
    const targetTab = hrefAttr ? hrefAttr.substring(1) : null;
    if (!targetTab) { return; }

    if (!loadedTabs.has(targetTab)) {
      loadTabContent(targetTab);
      loadedTabs.add(targetTab);
    }
  });
}

/**
 * Load content for a specific tab via AJAX
 */
function loadTabContent(tabName) {
  const spinner = $(`#${tabName}-spinner`);
  const content = $(`#${tabName}-content`);

  spinner.show();

  new ElanRegistryAPI()
    .get(`${window.statisticsConfig.baseUrl}api/statistics-data.php`, {
      tab: tabName
    })
    .then(function (response) {
      renderTabContent(tabName, response.data);
    })
    .catch(function (error) {
      content.html(
        `<div class="alert alert-danger">Failed to load data: ${error.message || error}</div>`
      );
    })
    .finally(function () {
      spinner.hide();
    });
}

/**
 * Render content for specific tabs
 */
function renderTabContent(tabName, data) {
  const content = $(`#${tabName}-content`);

  switch (tabName) {
    case "geographic":
      renderGeographicTab(content, data);
      break;
    case "production":
      renderProductionTab(content, data);
      break;
    case "colors":
      renderColorsTab(content, data);
      break;
    case "quality":
      renderQualityTab(content, data);
      break;
  }
}

/**
 * Render Geographic Tab Content
 */
function renderGeographicTab(container, data) {
  const html = `
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Cars by Country</h5>
                    </div>
                    <div class="card-body" style="height: 400px;">
                        <canvas id="countryChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Top Countries (Detailed)</h5>
                    </div>
                    <div class="card-body" style="height: 400px;">
                        <canvas id="countryDistributionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">US State Distribution</h5>
                    </div>
                    <div class="card-body" style="height: 600px;">
                        <canvas id="usStatesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    `;

  container.html(html);

  // Create charts
  createCountryChart(data.country);
  createCountryDistributionChart(data.countryDistribution);
  createUSStatesChart(data.usStates);
}

/**
 * Render Production Tab Content
 */
function renderProductionTab(container, data) {
  const html = `
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Cars by Type</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="typeChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Cars by Series</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="seriesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Cars by Variant</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="variantChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Production by Year</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="productionYearChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Early vs Late Production</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="earlyLateChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Series Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Series</th>
                                        <th>Registered</th>
                                        <th>Total Produced</th>
                                        <th>% Recorded</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${renderSeriesTable(
                                      data.seriesCounts,
                                      data.seriesNotes
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

  container.html(html);

  // Create charts
  createTypeChart(data.type);
  createSeriesChart(data.series);
  createVariantChart(data.variant);
  createProductionYearChart(data.productionByYear);
  createEarlyLateChart(data.earlyVsLate);
}

/**
 * Render Colors Tab Content
 */
function renderColorsTab(container, data) {
  const html = `
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Color Distribution</h5>
                    </div>
                    <div class="card-body" style="height: 400px;">
                        <canvas id="colorDistributionChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Color Trends by Year</h5>
                    </div>
                    <div class="card-body" style="height: 400px;">
                        <canvas id="colorByYearChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Color Distribution by Series</h5>
                    </div>
                    <div class="card-body" style="height: 500px;">
                        <canvas id="colorBySeriesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    `;

  container.html(html);

  // Create charts
  createColorDistributionChart(data.colors);
  createColorByYearChart(data.colorByYear);
  createColorBySeriesChart(data.colorBySeries);
}

/**
 * Render Quality Tab Content
 */
function renderQualityTab(container, data) {
  const completeness = data.completeness;
  const totalCars = completeness.total_cars;

  const html = `
        <div class="row">
            <div class="col-lg-8 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Data Completeness Overview</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="dataCompletenessChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Quality Metrics</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <h6 class="text-muted mb-1">Total Registered Cars</h6>
                            <h3 class="mb-0 text-primary">${totalCars.toLocaleString()}</h3>
                        </div>
                        <hr>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Chassis Numbers:</span>
                                <span class="fw-bold">${Math.round(
                                  (completeness.has_chassis / totalCars) * 100
                                )}%</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Color Information:</span>
                                <span class="fw-bold">${Math.round(
                                  (completeness.has_color / totalCars) * 100
                                )}%</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Engine Details:</span>
                                <span class="fw-bold">${Math.round(
                                  (completeness.has_engine / totalCars) * 100
                                )}%</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Purchase Dates:</span>
                                <span class="fw-bold">${Math.round(
                                  (completeness.has_purchase_date / totalCars) *
                                    100
                                )}%</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Photos:</span>
                                <span class="fw-bold">${Math.round(
                                  (completeness.has_image / totalCars) * 100
                                )}%</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Location Data:</span>
                                <span class="fw-bold">${Math.round(
                                  (completeness.has_location / totalCars) * 100
                                )}%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

  container.html(html);

  // Create chart
  createDataCompletenessChart(completeness);
}

/**
 * Helper function to render series table
 */
function renderSeriesTable(counts, notes) {
  let html = "";
  for (const [series, count] of Object.entries(counts)) {
    const produced = parseInt(notes[series]) || 0;
    const percentage = produced > 0 ? Math.round((count / produced) * 100) : 0;

    html += `
            <tr>
                <td class="fw-bold">${series.toUpperCase()}</td>
                <td>${count}</td>
                <td>${produced.toLocaleString()}</td>
                <td>${percentage}%</td>
            </tr>
        `;
  }
  return html;
}

/**
 * Update overview metrics cards
 */
function updateOverviewMetrics() {
  // Update static counts from loaded data
  $("#totalCountries").text(window.statisticsRawData.countriesCount || "-");
  $("#totalColors").text(window.statisticsRawData.colorsCount || "-");

  // Calculate timeline-based metrics
  if (
    window.statisticsRawData.timeline &&
    window.statisticsRawData.timeline.length > 0
  ) {
    $("#totalCars").text(
      window.statisticsRawData.timeline.length.toLocaleString()
    );

    // Calculate this year's registrations
    const thisYear = new Date().getFullYear();
    const thisYearCount = window.statisticsRawData.timeline.filter((item) => {
      const year = new Date(item.ctime).getFullYear();
      return year === thisYear;
    }).length;

    $("#registrationGrowth").text(thisYearCount.toLocaleString());
  }
}

// ===== CHART CREATION FUNCTIONS =====

/**
 * Create Timeline Chart (Line Chart)
 */
function createTimelineChart() {
  const data = window.statisticsRawData.timeline || [];

  // Process timeline data into monthly counts
  const monthlyCounts = {};
  data.forEach((item) => {
    const date = new Date(item.ctime);
    const monthKey = `${date.getFullYear()}-${String(
      date.getMonth() + 1
    ).padStart(2, "0")}`;
    monthlyCounts[monthKey] = (monthlyCounts[monthKey] || 0) + 1;
  });

  // Convert to cumulative data
  const sortedMonths = Object.keys(monthlyCounts).sort();
  let cumulative = 0;
  const chartData = sortedMonths.map((month) => {
    cumulative += monthlyCounts[month];
    return { x: month, y: cumulative };
  });

  const canvasEl = document.getElementById("timelineChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.timeline = new Chart(ctx, {
    type: "line",
    data: {
      datasets: [
        {
          label: "Registry Growth",
          data: chartData,
          borderColor: BOOTSTRAP_COLORS.primary,
          backgroundColor: BOOTSTRAP_COLORS.primary + "20",
          fill: true,
          tension: 0.4
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: {
          type: "category",
          title: {
            display: true,
            text: "Time Period"
          }
        },
        y: {
          title: {
            display: true,
            text: "Cumulative Registrations"
          }
        }
      },
      plugins: {
        legend: {
          display: false
        }
      }
    }
  });
}

/**
 * Create Recent Activity Chart — 13-week zoom of the Registry Timeline.
 * Reuses statisticsRawData.timeline; no separate backend query needed.
 */
function createRecentActivityChart() {
  const data = window.statisticsRawData.timeline || [];

  // Returns a Date set to midnight (local time) of the Monday that starts the week containing `date`.
  function weekMonday(date) {
    const d = new Date(date);
    d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
    d.setHours(0, 0, 0, 0);
    return d;
  }

  // Build ordered list of 13 week-start Mondays, oldest first
  const thisMonday = weekMonday(new Date());
  const weeks = [];
  for (let i = 12; i >= 0; i--) {
    const d = new Date(thisMonday);
    d.setDate(thisMonday.getDate() - i * 7);
    weeks.push(d);
  }
  const cutoff = weeks[0];

  // Bucket each registration into its week's Monday
  const weeklyCounts = {};
  data.forEach((item) => {
    const date = new Date(item.ctime);
    if (date < cutoff) return;
    const key = weekMonday(date).toISOString().slice(0, 10);
    weeklyCounts[key] = (weeklyCounts[key] || 0) + 1;
  });

  // Build cumulative count series across the 13-week window
  let cumulative = 0;
  const labels = [];
  const cumulativeCounts = [];
  weeks.forEach((monday) => {
    const key = monday.toISOString().slice(0, 10);
    cumulative += weeklyCounts[key] || 0;
    labels.push(monday.toLocaleDateString("en-GB", { month: "short", day: "numeric" }));
    cumulativeCounts.push(cumulative);
  });

  const canvasEl = document.getElementById("recentActivityChart");
  if (!canvasEl) {
    console.error("[ElanRegistry] createRecentActivityChart: canvas #recentActivityChart not found");
    return;
  }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.recentActivity = new Chart(ctx, {
    type: "line",
    data: {
      labels: labels,
      datasets: [
        {
          label: "New Registrations",
          data: cumulativeCounts,
          borderColor: BOOTSTRAP_COLORS.primary,
          backgroundColor: BOOTSTRAP_COLORS.primary + "20",
          fill: true,
          tension: 0.4
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: {
          title: {
            display: true,
            text: "Week"
          }
        },
        y: {
          beginAtZero: true,
          ticks: {
            precision: 0,
            stepSize: 1
          },
          title: {
            display: true,
            text: "New Registrations"
          }
        }
      },
      plugins: {
        legend: {
          display: false
        }
      }
    }
  });
}

/**
 * Create Country Chart (Doughnut)
 */
function createCountryChart(data) {
  if (!Array.isArray(data)) {
    console.error("[ElanRegistry] createCountryChart: expected array, got:", data);
    return;
  }
  const sorted = [...data].sort((a, b) => parseInt(b.count) - parseInt(a.count));
  const top10 = sorted.slice(0, 10);
  const otherSum = sorted.slice(10).reduce((sum, item) => sum + parseInt(item.count), 0);
  const labels = top10.map((item) => item.country);
  const values = top10.map((item) => parseInt(item.count));
  if (otherSum > 0) {
    labels.push("Other");
    values.push(otherSum);
  }

  const canvasEl = document.getElementById("countryChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.country = new Chart(ctx, {
    type: "doughnut",
    data: {
      labels: labels,
      datasets: [
        {
          data: values,
          backgroundColor: CHART_COLORS
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      }
    }
  });
}

/**
 * Create Country Distribution Chart (Bar)
 */
function createCountryDistributionChart(data) {
  const labels = data.map((item) => item.country);
  const values = data.map((item) => parseInt(item.count));

  const canvasEl = document.getElementById("countryDistributionChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.countryDistribution = new Chart(ctx, {
    type: "bar",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Cars Registered",
          data: values,
          backgroundColor: BOOTSTRAP_COLORS.primary,
          borderColor: BOOTSTRAP_COLORS.primary,
          borderWidth: 1
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true
        }
      },
      plugins: {
        legend: {
          display: false
        }
      }
    }
  });
}

/**
 * Create US States Chart (Horizontal Bar)
 */
function createUSStatesChart(data) {
  const labels = data.map((item) => item.normalized_state);
  const values = data.map((item) => parseInt(item.count));

  const canvasEl = document.getElementById("usStatesChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.usStates = new Chart(ctx, {
    type: "bar",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Cars Registered",
          data: values,
          backgroundColor: BOOTSTRAP_COLORS.primary,
          borderColor: BOOTSTRAP_COLORS.primary,
          borderWidth: 1
        }
      ]
    },
    options: {
      indexAxis: "y",
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: {
          beginAtZero: true
        }
      },
      plugins: {
        legend: {
          display: false
        }
      }
    }
  });
}

/**
 * Create Type Chart (Doughnut)
 */
function createTypeChart(data) {
  const labels = data.map((item) => item.type);
  const values = data.map((item) => parseInt(item.count));

  const canvasEl = document.getElementById("typeChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.type = new Chart(ctx, {
    type: "doughnut",
    data: {
      labels: labels,
      datasets: [
        {
          data: values,
          backgroundColor: CHART_COLORS
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      }
    }
  });
}

/**
 * Create Series Chart (Doughnut)
 */
function createSeriesChart(data) {
  const labels = data.map((item) => item.series);
  const values = data.map((item) => parseInt(item.count));

  const canvasEl = document.getElementById("seriesChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.series = new Chart(ctx, {
    type: "doughnut",
    data: {
      labels: labels,
      datasets: [
        {
          data: values,
          backgroundColor: CHART_COLORS
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      }
    }
  });
}

/**
 * Create Variant Chart (Doughnut)
 */
function createVariantChart(data) {
  const labels = data.map((item) => item.variant);
  const values = data.map((item) => parseInt(item.count));

  const canvasEl = document.getElementById("variantChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.variant = new Chart(ctx, {
    type: "doughnut",
    data: {
      labels: labels,
      datasets: [
        {
          data: values,
          backgroundColor: CHART_COLORS
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      }
    }
  });
}

/**
 * Create Production Year Chart (Bar)
 */
function createProductionYearChart(data) {
  const labels = data.map((item) => item.year);
  const values = data.map((item) => parseInt(item.count));

  const canvasEl = document.getElementById("productionYearChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.productionYear = new Chart(ctx, {
    type: "bar",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Cars Registered",
          data: values,
          backgroundColor: BOOTSTRAP_COLORS.primary,
          borderColor: BOOTSTRAP_COLORS.primary,
          borderWidth: 1
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true
        }
      },
      plugins: {
        legend: {
          display: false
        }
      }
    }
  });
}

/**
 * Create Early vs Late Production Chart (Pie)
 */
function createEarlyLateChart(data) {
  const labels = data.map((item) => item.period);
  const values = data.map((item) => parseInt(item.count));

  const canvasEl = document.getElementById("earlyLateChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.earlyLate = new Chart(ctx, {
    type: "pie",
    data: {
      labels: labels,
      datasets: [
        {
          data: values,
          backgroundColor: [
            BOOTSTRAP_COLORS.primary,
            BOOTSTRAP_COLORS.secondary
          ]
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      }
    }
  });
}

/**
 * Create Color Distribution Chart (Doughnut)
 */
function createColorDistributionChart(data) {
  const normalizedData = {};
  data.forEach((item) => {
    const normalizedColor = normalizeColorName(item.color);
    if (!normalizedData[normalizedColor]) {
      normalizedData[normalizedColor] = 0;
    }
    normalizedData[normalizedColor] += parseInt(item.count);
  });

  // Convert back to arrays
  const labels = Object.keys(normalizedData);
  const values = Object.values(normalizedData);

  const backgroundColors = labels.map((colorName) => getColorForName(colorName));
  const borders = backgroundColors.map(swatchBorder);

  const canvasEl = document.getElementById("colorDistributionChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.colorDistribution = new Chart(ctx, {
    type: "doughnut",
    data: {
      labels: labels,
      datasets: [
        {
          data: values,
          backgroundColor: backgroundColors,
          borderColor: borders.map((b) => b.borderColor),
          borderWidth: borders.map((b) => b.borderWidth)
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      }
    }
  });
}

/**
 * Create Color by Year Chart (Line)
 */
function createColorByYearChart(data) {
  const colorData = {};
  data.forEach((item) => {
    const normalizedColor = normalizeColorName(item.color);
    if (!colorData[normalizedColor]) {
      colorData[normalizedColor] = {};
    }
    if (!colorData[normalizedColor][item.year]) {
      colorData[normalizedColor][item.year] = 0;
    }
    colorData[normalizedColor][item.year] += parseInt(item.count);
  });

  // Get all years and create datasets
  const years = [...new Set(data.map((item) => item.year))].sort();
  const datasets = Object.keys(colorData)
    .slice(0, 8)
    .map((color) => {
      const colorHex = getColorForName(color);
      const displayColor = isLightColor(colorHex) ? "#9E9E9E" : colorHex;
      return {
        label: color,
        data: years.map((year) => colorData[color][year] || 0),
        borderColor: displayColor,
        backgroundColor: displayColor + "20",
        _origColor: displayColor,
        fill: false,
        tension: 0.1,
        borderWidth: 2,
        pointRadius: 3
      };
    });

  const canvasEl = document.getElementById("colorByYearChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.colorByYear = new Chart(ctx, {
    type: "line",
    data: {
      labels: years,
      datasets: datasets
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true
        }
      },
      onHover: function (event, elements, chart) {
        const hoveredIndex = elements.length > 0 ? elements[0].datasetIndex : -1;
        chart.data.datasets.forEach((ds, i) => {
          if (!ds._origColor) {
            console.error("[ElanRegistry] onHover: dataset", i, "missing _origColor");
            return;
          }
          const isActive = hoveredIndex === -1 || i === hoveredIndex;
          ds.borderColor = isActive ? ds._origColor : ds._origColor + "30";
          ds.borderWidth = hoveredIndex === -1 ? 2 : isActive ? 2.5 : 1;
          ds.pointRadius = isActive ? 3 : 0;
        });
        chart.update("none");
      }
    }
  });
}

/**
 * Create Color by Series Chart (Stacked Bar)
 */
function createColorBySeriesChart(data) {
  const seriesData = {};
  const colorSet = new Set();

  data.forEach((item) => {
    const normalizedColor = normalizeColorName(item.color);
    colorSet.add(normalizedColor);

    if (!seriesData[item.series]) {
      seriesData[item.series] = {};
    }
    if (!seriesData[item.series][normalizedColor]) {
      seriesData[item.series][normalizedColor] = 0;
    }
    seriesData[item.series][normalizedColor] += parseInt(item.count);
  });

  const series = Object.keys(seriesData);
  const allColors = Array.from(colorSet);
  const datasets = allColors.map((color) => {
    const colorHex = getColorForName(color);
    return {
      label: color,
      data: series.map((s) => seriesData[s][color] || 0),
      backgroundColor: colorHex,
      ...swatchBorder(colorHex)
    };
  });

  const canvasEl = document.getElementById("colorBySeriesChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.colorBySeries = new Chart(ctx, {
    type: "bar",
    data: {
      labels: series,
      datasets: datasets
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: {
          stacked: true
        },
        y: {
          stacked: true,
          beginAtZero: true
        }
      }
    }
  });
}

/**
 * Create Data Completeness Chart (Radar)
 */
function createDataCompletenessChart(data) {
  const total = data.total_cars;
  const fields = [
    { label: "Chassis Numbers", value: data.has_chassis },
    { label: "Color Info", value: data.has_color },
    { label: "Engine Details", value: data.has_engine },
    { label: "Purchase Dates", value: data.has_purchase_date },
    { label: "Photos", value: data.has_image },
    { label: "Location Data", value: data.has_location }
  ];

  const labels = fields.map((f) => f.label);
  const percentages = fields.map((f) => Math.round((f.value / total) * 100));

  const canvasEl = document.getElementById("dataCompletenessChart");
  if (!canvasEl) { return; }
  const ctx = canvasEl.getContext("2d");
  window.statisticsCharts.dataCompleteness = new Chart(ctx, {
    type: "radar",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Completeness %",
          data: percentages,
          borderColor: BOOTSTRAP_COLORS.success,
          backgroundColor: BOOTSTRAP_COLORS.success + "30",
          pointBackgroundColor: BOOTSTRAP_COLORS.success,
          pointBorderColor: "#fff",
          pointHoverBackgroundColor: "#fff",
          pointHoverBorderColor: BOOTSTRAP_COLORS.success
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        r: {
          beginAtZero: true,
          max: 100,
          ticks: {
            stepSize: 20,
            callback: function (value) {
              return value + "%";
            }
          }
        }
      },
      plugins: {
        legend: {
          display: false
        }
      }
    }
  });
}

// ===== MAPLIBRE GL JS INTEGRATION =====

/**
 * Build the static "map unavailable" error UI using safe DOM APIs.
 * @param {HTMLElement} el - The map container element to populate.
 */
const renderMapErrorUI = (el) => {
  while (el.firstChild) {
    el.removeChild(el.firstChild);
  }
  const wrap = document.createElement("div");
  wrap.className =
    "d-flex flex-column align-items-center justify-content-center h-100 text-muted p-3";

  const msg = document.createElement("p");
  msg.className = "mb-2";
  msg.textContent = "Map unavailable. Please try refreshing.";
  wrap.appendChild(msg);

  const btn = document.createElement("button");
  btn.className = "btn btn-sm btn-outline-secondary";
  btn.type = "button";
  btn.textContent = "Retry";
  btn.addEventListener("click", function () {
    location.reload();
  });
  wrap.appendChild(btn);

  el.appendChild(wrap);
};

/**
 * Initialize MapLibre map
 */
function statisticsInitMap() {
  const mapEl = document.getElementById("map");
  if (!mapEl) return;
  if (typeof maplibregl === "undefined") {
    renderMapErrorUI(mapEl);
    return;
  }

  const map = new maplibregl.Map({
    container: "map",
    style: window.statisticsConfig.versatileStyleUrl,
    center: [-20, 30],
    zoom: 1,
    attributionControl: false
  });

  map.addControl(new maplibregl.AttributionControl({ compact: true }), "bottom-right");
  map.once("idle", function () {
    var attrEl = document.querySelector(".maplibregl-ctrl-attrib");
    if (attrEl) attrEl.classList.remove("maplibregl-compact-show");
  });
  map.addControl(
    new maplibregl.NavigationControl({ showCompass: false }),
    "top-right"
  );
  map.addControl(new maplibregl.FullscreenControl(), "top-right");

  map.on("load", function () {
    loadMapMarkers(map);
  });

  map.on("error", function (e) {
    // Tile and source load errors are transient — let MapLibre retry
    if (e.sourceId !== undefined || (e.error && typeof e.error.status === "number")) {
      console.warn("[ElanRegistry] Map tile/source error (non-fatal):", e.error);
      return;
    }
    const el = document.getElementById("map");
    if (el) {
      renderMapErrorUI(el);
    }
  });
}

function loadMapMarkers(map) {
  const markers = window.elanMapMarkers;
  if (!Array.isArray(markers) || markers.length === 0) return;

  const seriesClassMap = {
    sprint: "sprint",
    "+2": "plus2",
    s1: "s1",
    s2: "s2",
    s3: "s3",
    s4: "s4",
    "1500": "elan1500"
  };

  function markerClassForSeries(series, variant) {
    if ((variant || "").toLowerCase() === "race") return "r26";
    const s = (series || "").toLowerCase();
    for (const [key, cls] of Object.entries(seriesClassMap)) {
      if (s.includes(key)) return cls;
    }
    return "unknown";
  }

  function buildPopupNode(car) {
    const wrap = document.createElement("div");
    wrap.style.minWidth = "200px";
    wrap.style.fontSize = "14px";

    if (car.image) {
      const img = document.createElement("img");
      img.src = window.statisticsConfig.imageUrl + car.image;
      img.alt = "Car photo";
      img.style.cssText =
        "width:100px;height:75px;object-fit:cover;float:right;margin:0 0 8px 10px;border-radius:4px;";
      img.onerror = function () {
        this.style.display = "none";
      };
      wrap.appendChild(img);
    }

    const h6 = document.createElement("h6");
    h6.style.cssText = "margin-bottom:6px;font-weight:600;";
    h6.textContent = car.name;
    wrap.appendChild(h6);

    function addPara(label, value) {
      if (!value) return;
      const p = document.createElement("p");
      p.style.marginBottom = "4px";
      const strong = document.createElement("strong");
      strong.textContent = label + ": ";
      p.appendChild(strong);
      p.appendChild(document.createTextNode(value));
      wrap.appendChild(p);
    }

    addPara("Series", car.series);
    addPara("Variant", car.variant);
    addPara(
      "Location",
      [car.city, car.state, car.country].filter(Boolean).join(", ")
    );
    addPara("Owner", car.owner);

    if (car.id) {
      const p = document.createElement("p");
      p.style.marginBottom = "4px";
      const a = document.createElement("a");
      a.href =
        window.statisticsConfig.baseUrl +
        "../cars/details.php?car_id=" +
        encodeURIComponent(car.id);
      a.target = "_blank";
      a.rel = "noopener";
      a.textContent = "View Details";
      p.appendChild(a);
      wrap.appendChild(p);
    }

    const clear = document.createElement("div");
    clear.style.clear = "both";
    wrap.appendChild(clear);
    return wrap;
  }

  const markerList = [];

  markers.forEach(function (car) {
    const seriesClass = markerClassForSeries(car.series, car.variant);

    const el = document.createElement("div");
    el.className = "elan-marker-wrapper";
    const dot = document.createElement("div");
    dot.className = "elan-marker " + seriesClass;
    el.appendChild(dot);

    const popup = new maplibregl.Popup({ offset: 25 }).setDOMContent(
      buildPopupNode(car)
    );

    new maplibregl.Marker({ element: el, anchor: "bottom" })
      .setLngLat([car.lon, car.lat])
      .setPopup(popup)
      .addTo(map);

    markerList.push({ seriesClass: seriesClass, el: el });
  });

  initMarkerFilter(markerList);
}

const initMarkerFilter = (markerList) => {
  const allCheckbox = document.getElementById("filter-all");
  const seriesCheckboxes = document.querySelectorAll("#map-series-filter input[data-series]");

  if (!allCheckbox || seriesCheckboxes.length === 0) {
    console.warn("[ElanRegistry] initMarkerFilter: filter DOM elements not found; marker filtering disabled.");
    return;
  }

  function getCheckedSeries() {
    const checked = new Set();
    seriesCheckboxes.forEach(function (cb) {
      if (cb.checked) checked.add(cb.dataset.series);
    });
    return checked;
  }

  function applyFilter() {
    const checked = getCheckedSeries();
    markerList.forEach(function (item) {
      item.el.style.display = checked.has(item.seriesClass) ? "" : "none";
    });
  }

  function syncAllCheckbox() {
    const allChecked = Array.from(seriesCheckboxes).every(function (cb) { return cb.checked; });
    allCheckbox.checked = allChecked;
    allCheckbox.indeterminate = !allChecked && Array.from(seriesCheckboxes).some(function (cb) { return cb.checked; });
  }

  allCheckbox.addEventListener("change", function () {
    const state = allCheckbox.checked;
    seriesCheckboxes.forEach(function (cb) { cb.checked = state; });
    allCheckbox.indeterminate = false;
    applyFilter();
  });

  seriesCheckboxes.forEach(function (cb) {
    cb.addEventListener("change", function () {
      syncAllCheckbox();
      applyFilter();
    });
  });
};

window.statisticsInitMap = statisticsInitMap;

/**
 * Cleanup function to destroy charts when needed
 */
function destroyAllCharts() {
  Object.values(window.statisticsCharts).forEach((chart) => {
    if (chart && typeof chart.destroy === "function") {
      chart.destroy();
    }
  });
  window.statisticsCharts = {};
}

