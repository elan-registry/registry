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
        <p>Hello <?= ucfirst($car->fname) ?>,
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
                <td id="column"><?= $car->user_id ?></th>
            </tr>
            <tr>
                <td>First Name</td>
                <td><?= $car->fname ?>
                </td>
            </tr>
            <tr>
                <td>Last Name</td>
                <td><?= $car->lname ?>
                </td>
            </tr>
            <tr>
                <td>Email</td>
                <td><?= $car->email ?>
                </td>
            </tr>
            <tr>
                <td>City</td>
                <td><?= $car->city ?>
                </td>
            </tr>
            <tr>
                <td>State</td>
                <td><?= $car->state ?>
                </td>
            </tr>
            <tr>
                <td>Country</td>
                <td><?= $car->country ?>
                </td>
            </tr>
            <tr>
                <td>Join Date</td>
                <td><?= $car->join_date ?>
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
                <th scope=column><?= $car->id ?></th>
            </tr>
            <tr>
                <td>Year</td>
                <td><?= $car->year ?>
                </td>
            </tr>
            <tr>
                <td>Type</td>
                <td><?= $car->type ?>
                </td>
            </tr>
            <tr>
                <td>Chassis</td>
                <td><?= $car->chassis ?>
                </td>
            </tr>
            <tr>
                <td>Series</td>
                <td><?= $car->series ?>
                </td>
            </tr>
            <tr>
                <td>Variant</td>
                <td><?= $car->variant ?>
                </td>
            </tr>
            <tr>
                <td>Color</td>
                <td><?= $car->color ?>
                </td>
            </tr>
            <tr>
                <td>Purchase Date</td>
                <td><?= $car->purchasedate ?>
                </td>
            </tr>
            <tr>
                <td>Sold Date</td>
                <td><?= $car->solddate ?>
                </td>
            </tr>
            <tr>
                <td>Comment</td>
                <td><?= $car->comments ?>
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
                if (!empty($car->website)) {
                    echo '<td> <a target="_blank" href="' . $car->website . '">Website</a></td>';
                } else {
                    echo "<td></td>";
                }
                ?>
            </tr>
            <tr>
                <td>Date Added</td>
                <td><?= $car->ctime ?>
                </td>
            </tr>
        </table>

        <!-- end section -->

        <!-- footer ====== -->

        <!-- end footer ====== -->
    </div>
</body>

</html>