<?php

//
// check whether it is a browser or an API request
// if its a browser then we want to show a dashboard instead of catching the request
//

$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$is_a_browser = (strpos($accept, 'text/html') !== false);

/**
 * delete an entire directory used for clearing logs
 * @param string $dir Path to the directory.
 * @return bool True on success, false on failure.
 */
function delete_directory($dir)
{
    if (!file_exists($dir)) {
        return true;
    }

    if (!is_dir($dir)) {
        return unlink($dir);
    }

    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        if (!delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }

    return rmdir($dir);
}

if ($is_a_browser) {

    $filename = 'log.html';

    if (isset($_GET['clear-logs'])) {
        delete_directory('uploads');
        unlink($filename);
        header('Location: /');
        exit;
    }

    $dashboard = file_get_contents('dashboard.html');

    if (!file_exists($filename)) {
        $dashboard = str_replace('[LOGS]', '<b style="font-size: 2rem; display: block; text-align: center;">No Request Yet</b>', $dashboard);
    } else {
        $dashboard = str_replace('[LOGS]', file_get_contents($filename), $dashboard);
    }

    echo $dashboard;
    exit;
}

//
// but if its an API request then we want to catch it and log it
//

/**
 * log the incoming HTTP request
 * @param string $type   the request type
 * @param array  $data   the request data
 * @param array  $header the request header
 */
function log_http_request(string $type, array $data, array $header)
{
    $type_background_color = 'rgb(119, 255, 119)';
    $type_text_color       = 'rgb(0, 92, 25)';

    if ($type == 'POST') {
        $type_background_color = 'rgb(210, 119, 255)';
        $type_text_color       = 'rgb(92, 0, 77)';
    }

    if ($type == 'PUT') {
        $type_background_color = 'rgb(119, 194, 255)';
        $type_text_color       = 'rgb(0, 14, 92)';
    }

    if ($type == 'PATCH') {
        $type_background_color = 'rgb(255, 198, 119)';
        $type_text_color       = 'rgb(92, 61, 0)';
    }

    if ($type == 'DELETE') {
        $type_background_color = 'rgb(255, 119, 119)';
        $type_text_color       = 'rgb(92, 0, 0)';
    }

    if ($type == 'UPLOAD') {
        $type_background_color = 'hsl(0, 0%, 65%)';
        $type_text_color       = 'hsl(0, 0%, 18%)';
    }

    $header_string = '';
    foreach($header as $name => $content) {
        $header_string .= "<div style=\"text-align: right\"><b>{$name}:</b></div><div>{$content}</div>" . PHP_EOL;
    }
    $header_string = rtrim($header_string, PHP_EOL);

    $files = [];
    if (isset($data['$_FILES'])) {
        $files = $data['$_FILES'];
        unset($data['$_FILES']);
    }

    $data_json = json_encode($data, JSON_PRETTY_PRINT);

    if (!empty($files)) {
        $data_json .= "\n\n<b>Uploaded Files:</b>";
    }
    foreach ($files as $file) {
        $data_json .= "\n<a href=\"{$file}\" target=\"_blank\">{$file}</a>";
    }

    $timestamp = date('d-m-Y H:i:s');
    $html_log = file_get_contents("log_template.html");
    $html_log = str_replace([
        '[TYPE-BACKGROUND]',
        '[TYPE-COLOR]',
        '[TYPE]',
        '[TIMESTAMP]',
        '[HEADERS]',
        '[DATA]',
    ], [
        $type_background_color,
        $type_text_color,
        $type,
        $timestamp,
        $header_string,
        $data_json,
    ], $html_log);

    $filename = 'log.html';

    if (!file_exists($filename)) {
        $file = fopen($filename, 'w');
        fwrite($file, $html_log);
        fclose($file);
    } else {
        file_put_contents($filename, $html_log . file_get_contents($filename));
    }
}

/**
 * process file to uploads
 */
function process_file_uploads($path = "uploads/")
{
    $path = rtrim($path, '/') . '/';
    if (!file_exists($path)) {
        mkdir($path, 0777, true);
    }

    if (empty($_FILES)) {
        return null;
    }

    $links = [];

    foreach ($_FILES as $file) {
        // upload multiple files as an array
        if (is_array($file['name'])) {
            foreach ($file['name'] as $i => $name) {
                if ($file['error'][$i] !== UPLOAD_ERR_OK) continue;

                $fileName = basename($name);
                $targetFile = $path . $fileName;

                if (move_uploaded_file($file['tmp_name'][$i], $targetFile)) {
                    $links[] = $targetFile;
                }
            }
        // upload single file
        } else {
            if ($file['error'] !== UPLOAD_ERR_OK) continue;

            $fileName = basename($file["name"]);
            $targetFile = $path . $fileName;

            if (move_uploaded_file($file["tmp_name"], $targetFile)) {
                $links[] = $targetFile;
            }
        }
    }

    return !empty($links) ? $links : 'Files were received, but could not be moved to the target folder.';
}

//
// if $_POST is empty, it may be that the client is sending via JSON, so we need to raw parse it
//

if (empty($_POST)) {

    $raw_data = file_get_contents("php://input");
    $json = json_decode($raw_data, true);

    if ($json != null) {
        $_REQUEST = $json;
    } else {
        parse_str($raw_data, $_REQUEST);
    }

}

//
// Handle GET request
//

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET)) {
    log_http_request('GET', $_GET, getallheaders());
    header('Content-Type: application/json');
    echo json_encode($_GET, JSON_PRETTY_PRINT);
    exit;
}

//
// Handle POST request
//

if (($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_REQUEST)) || !empty($_FILES)) {

    $files = process_file_uploads();
    if ($files != null) {
        $_REQUEST['$_FILES'] = $files;
        log_http_request('UPLOAD', $_REQUEST, getallheaders());
    } else {
        log_http_request('POST', $_REQUEST, getallheaders());
    }

    header('Content-Type: application/json');
    echo json_encode($_REQUEST, JSON_PRETTY_PRINT);
    exit;
}

//
// Handle PUT request
//

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    log_http_request('PUT', $_REQUEST, getallheaders());
    header('Content-Type: application/json');
    echo json_encode($_REQUEST, JSON_PRETTY_PRINT);
    exit;
}

//
// Handle PATCH request
//

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    log_http_request('PATCH', $_REQUEST, getallheaders());
    header('Content-Type: application/json');
    echo json_encode($_REQUEST, JSON_PRETTY_PRINT);
    exit;
}

//
// Handle DELETE request
//

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    log_http_request('DELETE', $_REQUEST, getallheaders());
    header('Content-Type: application/json');
    echo json_encode($_REQUEST, JSON_PRETTY_PRINT);
    exit;
}

//
// response by saying that there is no data sended
//

echo 'empty data: please send something to the server, method: ' . $_SERVER['REQUEST_METHOD'];
