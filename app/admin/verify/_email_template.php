<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width; initial-scale=1.0; maximum-scale=1.0;" />

    <title>Lotus Elan Registry - Request for Information Verification</title>

    <style type="text/css">
        .container {
            margin-left: 10%;
            margin-bottom: 10%;
            width: 80%;
        }

        .button {
            background-color: #337709;
            border: none;
            color: white;
            padding: 15px 32px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            margin: 4px 2px;
            cursor: pointer;
            border-radius: 15px;

        }

        .buttonEdit {
            background-color: #3366ff;
        }

        .buttonSold {
            background-color: #ff3300;
        }

        table.carTable {
            border: 1px solid #999999;
            background-color: #FFFFFF;
            width: 100%;
            text-align: left;
            border-collapse: collapse;
        }

        table.carTable td,
        table.carTable th {
            border: 1px solid #999999;
            padding: 3px 2px;
        }

        table.carTable tr:nth-child(even) {
            background: #efefef;
        }

        .blank_row {
            height: 1px !important;
            /* overwrites any other rules */
            background-color: #000000 !important;
        }
    </style>

</head>


<body class="respond" leftmargin="0" topmargin="0" marginwidth="0" marginheight="0">
    <div class="container">
        <!-- pre-header -->

        <!-- pre-header end -->
        <!-- header -->
        <p>Hello <?= htmlspecialchars(ucfirst((string)($car->fname ?? '')), ENT_QUOTES, 'UTF-8') ?>,
        </p>
        <p>It's been awhile since you created or updated the information in the Lotus Elan Registry.
            Please review the information below and let me know if the information is current, if you've sold the car,
            or to login to update the information.</p>
        <p>Thank you,</p>
        <p>Jim, aka The Registrar</p>
        <br>

        <!-- end header -->
        <!-- button section -->
        <a href="<?= $verify_btn ?>" class="button">Verify</a>
        <a href="<?= $edit_btn ?>" class="button buttonEdit">Edit</a>
        <a href="<?= $sold_btn ?>" class="button buttonSold">Sold</a>
        <br>
        <br>
        <!-- end section -->
        <!-- table section -->
        <h3 id="owner">Owner Information</h3>
        <table class="carTable" aria-describedby="owner">
            <col class="w-15" />
            <col class="w-85" />

            <tr class="email-header-row">
                <th id="column">User ID</th>
                <td id="column"><?= (int)$car->user_id ?></th>
            </tr>
            <tr>
                <td>First Name</td>
                <td><?= htmlspecialchars((string)($car->fname ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Last Name</td>
                <td><?= htmlspecialchars((string)($car->lname ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Email</td>
                <td><?= htmlspecialchars((string)($car->email ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>City</td>
                <td><?= htmlspecialchars((string)($car->city ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>State</td>
                <td><?= htmlspecialchars((string)($car->state ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Country</td>
                <td><?= htmlspecialchars((string)($car->country ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Join Date</td>
                <td><?= htmlspecialchars((string)($car->join_date ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
        </table>
        <br>
        <h3 id="info">Car Information</h3>
        <table class="carTable" aria-describedby="info">
            <col width="15%" />
            <col width="85%" />
            <tr class="email-header-row">
                <th scope=column>Car ID</th>
                <th scope=column><?= htmlspecialchars((string)($car->id ?? ''), ENT_QUOTES, 'UTF-8') ?></th>
            </tr>
            <tr>
                <td>Year</td>
                <td><?= htmlspecialchars((string)($car->year ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Type</td>
                <td><?= htmlspecialchars((string)($car->type ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Chassis</td>
                <td><?= htmlspecialchars((string)($car->chassis ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Series</td>
                <td><?= htmlspecialchars((string)($car->series ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Variant</td>
                <td><?= htmlspecialchars((string)($car->variant ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Color</td>
                <td><?= htmlspecialchars((string)($car->color ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Purchase Date</td>
                <td><?= htmlspecialchars((string)($car->purchasedate ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Sold Date</td>
                <td><?= htmlspecialchars((string)($car->solddate ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Comment</td>
                <td><?= htmlspecialchars((string)($car->comments ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <tr>
                <td>Image</td>
                <td><?= $image ?>
                </td>
            </tr>
            <tr>
                <td>Website</td>
                <?php
                $websiteScheme = !empty($car->website)
                    ? strtolower((string)parse_url((string)$car->website, PHP_URL_SCHEME))
                    : '';
                if (!empty($car->website) && in_array($websiteScheme, ['http', 'https'], true)) {
                    echo '<td> <a target="_blank" rel="noopener noreferrer" href="' . htmlspecialchars((string)$car->website, ENT_QUOTES, 'UTF-8') . '">Website</a></td>';
                } else {
                    echo "<td></td>";
                }
                ?>
            </tr>
            <tr>
                <td>Date Added</td>
                <td><?= htmlspecialchars((string)($car->ctime ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
        </table>

        <!-- end section -->

        <!-- footer ====== -->

        <!-- end footer ====== -->
    </div>
</body>

</html>