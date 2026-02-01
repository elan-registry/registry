/* exported MENU, populateMain, populateSub */
var MENU = [];
MENU[0] = []; // 
MENU[1] = []; // 1963
MENU[2] = []; // 1964
MENU[3] = []; // 1965
MENU[4] = []; // 1966
MENU[5] = []; // 1967
MENU[6] = []; // 1968
MENU[7] = []; // 1969
MENU[8] = []; // 1970
MENU[9] = []; // 1971
MENU[10] = []; // 1972
MENU[11] = []; // 1973
MENU[12] = []; // 1974

MENU[0][0] = new Option("----", "");

MENU[1][0] = new Option("1963", "1963");
MENU[1][1] = new Option("Elan 1500 ( Type 26 Elan 1500 Roadster )", "Elan 1500|Roadster|26");
MENU[1][2] = new Option("Elan 1600 ( Type 26 S1 Roadster )", "S1|Roadster|26");
MENU[1][3] = new Option("Racing Version ( Number 26-R-xx )", "S1|Race|26R");

MENU[2][0] = new Option("1964", "1964");
MENU[2][1] = new Option("Roadster 1600 ( Type 26 S1 Roadster )", "S1|Roadster|26");
MENU[2][2] = new Option("Roadster S2 ( Type 26 S2 Roadster )", "S2|Roadster|26");
MENU[2][3] = new Option("Racing Version ( Number 26-R-xx )", "S1|Race|26R");
MENU[2][4] = new Option("Racing Version ( Number 26-S2-xx )", "S2|Race|26R");

MENU[3][0] = new Option("1965", "1965");
MENU[3][1] = new Option("Roadster S2 ( Type 26 S2 Roadster )", "S2|Roadster|26");
MENU[3][2] = new Option("Coupe S3 Pre-Airflow ( Type 36 S3 FHC-preairflow )", "S3|FHC-preairflow|36");
MENU[3][3] = new Option("Racing Version ( Type 26 26R Race )", "26R|Race|26");
MENU[3][4] = new Option("Racing Version ( Number 26-S2-xx )", "S2|Race|26R");

MENU[4][0] = new Option("1966", "1966");
MENU[4][1] = new Option("Roadster S2 ( Type 26 S2 Roadster )", "S2|Roadster|26");
MENU[4][2] = new Option("Roadster S2 S/E ( Type 26 S2 S/E Roadster )", "S2 SE|Roadster|26");
MENU[4][3] = new Option("Coupe S3 Pre-Airflow ( Type 36 FHC-preairflow )", "S3|FHC-preairflow|36");
MENU[4][4] = new Option("Coupe S3 Airflow ( Type 36 S3 FHC )", "S3|FHC|36");
MENU[4][5] = new Option("Drophead S3 DHC ( Type 45 S3 DHC )", "S3|DHC|45");
MENU[4][6] = new Option("Drophead S3 S/E DHC ( Type 45 S3 S/E DHC )", "S3 SE|DHC|45");
MENU[4][7] = new Option("Racing Version ( Number 26-S2-xx )", "S2|Race|26R");

MENU[5][0] = new Option("1967", "1967");
MENU[5][1] = new Option("Coupe S3 ( Type 36 S3 FHC )", "S3|FHC|36");
MENU[5][2] = new Option("Coupe S3 S/E ( Type 36 S3 FHC )", "S3 SE|FHC|36");
MENU[5][3] = new Option("Drophead S3 ( Type 45 S3 DHC )", "S3|DHC|45");
MENU[5][4] = new Option("Drophead S3 S/E ( Type 45 S3 S/E DHC )", "S3 SE|DHC|45");
MENU[5][5] = new Option("Plus 2 ( Type 50 +2)", "+2|FHC|50");

MENU[6][0] = new Option("1968", "1968");
MENU[6][1] = new Option("Coupe S3 ( Type 36 S3 FHC )", "S3|FHC|36");
MENU[6][2] = new Option("Coupe S3 S/E ( Type 36 S3 S/E FHC )", "S3 SE|FHC|36");
MENU[6][3] = new Option("Coupe S4 ( Type 36 S4 FHC )", "S4|FHC|36");
MENU[6][4] = new Option("Coupe S4 S/E ( Type 36 S4 S/E FHC )", "S4 SE|FHC|36");
MENU[6][5] = new Option("Drophead S3 DHC ( Type 45 S3 DHC )", "S3|DHC|45");
MENU[6][6] = new Option("Drophead S3 S/E DHC ( Type 45 S3 S/E DHC )", "S3 SE|DHC|45");
MENU[6][7] = new Option("Drophead S4 DHC ( Type 45 S4 DHC )", "S4|DHC|45");
MENU[6][8] = new Option("Drophead S4 S/E DHC ( Type 45 S4 S/E DHC )", "S4 SE|DHC|45");
MENU[6][9] = new Option("Plus 2 ( Type 50 +2)", "+2|FHC|50");
MENU[6][10] = new Option("Plus 2 Federal ( Type 50 +2 Federal)", "+2|Federal|50");

MENU[7][0] = new Option("1969", "1969");
MENU[7][1] = new Option("Coupe S4 ( Type 36 S4 FHC )", "S4|FHC|36");
MENU[7][2] = new Option("Coupe S4 S/E ( Type 36 S4 S/E FHC )", "S4 SE|FHC|36");
MENU[7][3] = new Option("Drophead S4 ( Type 45 S4 DHC )", "S4|DHC|45");
MENU[7][4] = new Option("Drophead S4 S/E ( Type 45 S4 S/E DHC )", "S4 SE|DHC|45");
MENU[7][5] = new Option("Plus 2 ( Type 50 +2)", "+2|FHC|50");
MENU[7][6] = new Option("Plus 2S ( Type 50 +2S)", "+2S|FHC|50");
MENU[7][7] = new Option("Plus 2 Federal ( Type 50 +2 Federal)", "+2|Federal|50");
MENU[7][8] = new Option("Plus 2S Federal ( Type 50 +2S Federal)", "+2S|Federal|50");

MENU[8][0] = new Option("1970", "1970");
MENU[8][1] = new Option("Coupe S4 ( Type 36 S4 FHC )", "S4|FHC|36");
MENU[8][2] = new Option("Coupe S4 S/E ( Type 36 S4 S/E FHC )", "S4 SE|FHC|36");
MENU[8][3] = new Option("Drophead S4 ( Type 45 S4 DHC )", "S4|DHC|45");
MENU[8][4] = new Option("Drophead S4 S/E ( Type 45 S4 S/E DHC )", "S4 SE|DHC|45");
MENU[8][5] = new Option("Coupe Sprint ( Type 36 Sprint FHC )", "Sprint|FHC|36");
MENU[8][6] = new Option("Drophead Sprint ( Type 45 Sprint DHC )", "Sprint|DHC|45");
MENU[8][7] = new Option("Plus 2 ( Type 50 +2)", "+2|FHC|50");
MENU[8][8] = new Option("Plus 2S ( Type 50 +2S)", "+2S|FHC|50");
MENU[8][9] = new Option("Plus 2 Federal ( Type 50 +2 Federal)", "+2|Federal|50");
MENU[8][10] = new Option("Plus 2S Federal ( Type 50 +2S Federal)", "+2S|Federal|50");

MENU[9][0] = new Option("1971", "1971");
MENU[9][1] = new Option("Coupe S4 ( Type 36 S4 FHC )", "S4|FHC|36");
MENU[9][2] = new Option("Coupe S4 S/E ( Type 36 S4 S/E FHC )", "S4 SE|FHC|36");
MENU[9][3] = new Option("Drophead S4 ( Type 45 S4 DHC )", "S4|DHC|45");
MENU[9][4] = new Option("Drophead S4 S/E ( Type 45 S4 S/E DHC )", "S4 SE|DHC|45");
MENU[9][5] = new Option("Coupe Sprint ( Type 36 Sprint FHC )", "Sprint|FHC|36");
MENU[9][6] = new Option("Drophead Sprint ( Type 45 Sprint DHC )", "Sprint|DHC|45");
MENU[9][7] = new Option("Plus 2S 130 ( Type 50 +2S/130)", "+2S/130|FHC|50");

MENU[10][0] = new Option("1972", "1972");
MENU[10][1] = new Option("Coupe Sprint ( Type 36 Sprint FHC )", "Sprint|FHC|36");
MENU[10][2] = new Option("Drophead Sprint ( Type 45 Sprint DHC )", "Sprint|DHC|45");
MENU[10][3] = new Option("Plus 2S 130 ( Type 50 +2S/130)", "+2S/130|FHC|50");
MENU[10][4] = new Option("Plus 2S 130/5 ( Type 50 +2S/130/5)", "+2S/130/5|FHC|50");

MENU[11][0] = new Option("1973", "1973");
MENU[11][1] = new Option("Coupe Sprint ( Type 36 Sprint FHC )", "Sprint|FHC|36");
MENU[11][2] = new Option("Drophead Sprint ( Type 45 Sprint DHC )", "Sprint|DHC|45");
MENU[11][3] = new Option("Plus 2S 130 ( Type 50 +2S/130)", "+2S/130|FHC|50");
MENU[11][4] = new Option("Plus 2S 130/5 ( Type 50 +2S/130/5)", "+2S/130/5|FHC|50");

MENU[12][0] = new Option("1974", "1974");
MENU[12][1] = new Option("Plus 2S 130 ( Type 50 +2S/130)", "+2S/130|FHC|50");
MENU[12][2] = new Option("Plus 2S 130/5 ( Type 50 +2S/130/5)", "+2S/130/5|FHC|50");

function populateMain(mainSel, subSel) {
	var mainMenu = mainSel;
	var subMenu = subSel;
	mainMenu.options.length = 0;
	for (var i = 0; i < MENU.length; i++) {
		mainMenu.options[i] = MENU[i][0];
	}
	populateSub(mainMenu, subMenu);
}

function populateSub(mainSel, subSel) {
	var mainMenu = mainSel;
	var subMenu = subSel;
	var optMainMenu;
	subMenu.options.length = 1;
	optMainMenu = mainMenu.selectedIndex;
	for (var i = 1; i < MENU[optMainMenu].length; i++) {
		subMenu.options[i] = MENU[optMainMenu][i];
	}
}

