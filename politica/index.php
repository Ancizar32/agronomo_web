<!-- index.php -->
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Visor de PDF - AgroSoft</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
        }

        iframe {
            width: 100%;
            height: 100vh;
            border: none;
        }
    </style>
</head>

<body>

    <?php
    // Ruta al PDF (puede estar en la misma carpeta que este archivo)
    $pdfFile = 'politica.pdf';

    // Validar que exista el archivo
    if (file_exists($pdfFile)) {
        echo "<iframe src='$pdfFile'></iframe>";
    } else {
        echo "<p style='padding:20px;'>⚠️ El archivo PDF no se encontró.</p>";
    }
    ?>

</body>

</html>