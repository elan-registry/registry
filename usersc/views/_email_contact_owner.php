<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width; initial-scale=1.0;" />

    <title>Lotus Elan Registry - Owner to Owner Email</title>
    <style type="text/css">
        .container {
            margin-left: 10%;
            margin-bottom: 10%;
            width: 80%;
        }

        table {
            border: 1px solid #999999;
            background-color: #FFFFFF;
            width: 100%;
            text-align: left;
            border-collapse: collapse;
        }

        table td,
        table th {
            border: 1px solid #999999;
            padding: 3px 2px;
        }

        table tr:nth-child(even) {
            background: #efefef;
        }

        .blank_row {
            height: 1px !important;
            /* overwrites any other rules */
            background-color: #000000 !important;
        }
    </style>

</head>

<body>
    <div class="container" id='message'>
        <p>Hello <?= $to ?>,</p>
        <p>This is a message from the <a href='https://www.elanregistry.org'>Lotus Elan Registry</a>. Another Elan owner has sent you a message.</p>
        <table aria-describedby='message'>
            <tr style="background-color:#9fc77f">
                <th colspan="2" id='email'>Message</th>
            </tr>
            <tr>
                <td><strong>From</strong></td>
                <td> <?= $from ?> </td>
            </tr>

            <tr>
                <td><strong>Email</strong></td>
                <td> <?= $fromEmail ?> </td>
            </tr>
            <tr class='blank_row'>
                <td colspan="2"></td>
            </tr>
            <tr>
                <td colspan="2">
                    <pre><?= $message ?> </pre>
                </td>
            </tr>
        </table>
    </div>
</body>

</html>