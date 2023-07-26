<?php

$dataBrut = file_get_contents(dirname(__DIR__)."/drcsis_access.log");

require_once dirname(__DIR__)."/.env";

$data = explode("\n", $dataBrut);

//var_dump($data[0]);

$startChar = [
    "[", 
    '"'
];
$endChar = [
    "]", 
    '"'
];
$param = [
    "CLIENT_IP" => 0,
    "OTHER_1" => 1,
    "OTHER_2" => 2,
    "DATE" => 3,
    "METHOD_AND_PATH" => 4,
    "STATUS_CODE" => 5,
    "LENGHT" => 6,
    "SERVER_HOST" => 7,
    "DEVICE" => 8,
];
$normalData = [];


foreach ($data as $key => $item) {

    $arrItem = explode(" ", $item);

    $buffer = null;
    $itemNormalData = [];
    $start = "";
    foreach ($arrItem as $k => $el) {
        //var_dump($el);
        $first = $el[0]??"";
        $startReserve = array_search($first, $startChar);
        $last = $el[strlen($el)-1]??"";
        $endReserve = array_search($last, $endChar);

        if (is_null($buffer) && $startReserve === false) {

            //var_dump($el);
            array_push($itemNormalData, $el);
            $start = "";
            
            //array_search()

        }
        elseif (is_null($buffer) && ($startReserve === 0 || !!$startReserve)) {

            $start = $startReserve;
            //var_dump($el);
            //$buffer = $el;
            //var_dump($startReserve);
            //var_dump($endReserve);
            //die();
            $buffer = substr($el, 1);
            if ($start === $endReserve) {
                //var_dump(substr($buffer, 0, strlen($buffer)-1));
                array_push($itemNormalData, substr($buffer, 0, strlen($buffer)-1));
                $start = "";
                $buffer = null;
            }

        }
        elseif (!is_null($buffer)) 
        {
            $buffer = $buffer . " " . $el;

            if ($start === $endReserve) {
                
                //var_dump(substr($buffer, 0, strlen($buffer)-1) );
                array_push($itemNormalData, substr($buffer, 0, strlen($buffer)-1));
                $start = "";
                $buffer = null;
            }
            //$buffer .= $el;

        }else {
            $buffer = $buffer . " " . $el;
        }


        
    }
    //$itemNormalData
    array_push($normalData, $itemNormalData);
}

$transformData = array_map(
    function (array $item) use ($param)
    {
        return [
            "ip" => $item[$param["CLIENT_IP"]]??null,
            "other1" => $item[$param["OTHER_1"]]??null,
            "other2" => $item[$param["OTHER_2"]]??null,
            "access_at" => new DateTime($item[$param["DATE"]]??null),
            "method_and_path" => $item[$param["METHOD_AND_PATH"]]??null,
            "lenght" => $item[$param["LENGHT"]]??null,
            "status_code" => $item[$param["STATUS_CODE"]]??null,
            "server_host" => $item[$param["SERVER_HOST"]]??null,
            "device" => $item[$param["DEVICE"]]??null,
        ];
    },
    $normalData
);



class DBTransaction
{
    protected $pdo;
    public $last_insert_id;

    public function __construct()
    {

        $this->pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function startTransaction()
    {
        $this->pdo->beginTransaction();
    }

    public function insertTransaction($sql, $data)
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        $this->last_insert_id = $this->pdo->lastInsertId();
    }

    public function addLogAccess(array $data)
    {
        $sql = "INSERT INTO ". TABLE_NAME . " (
                    ip ,
                    other1,
                    other2,
                    access_at,
                    method_and_path,
                    lenght,
                    status_code,
                    server_host,
                    device  
                ) 
            VALUES ( 
                :ip,
                :other1,
                :other2,
                :access_at,
                :method_and_path,
                :lenght,
                :status_code,
                :server_host,
                :device  
             )";
        $dataTransform = [
            "ip" => $data["ip"],
            "other1" => $data["other1"],
            "other2" => $data["other2"],
            "access_at" => $data["access_at"]->format(DateTimeInterface::RFC3339_EXTENDED),
            "method_and_path" => $data["method_and_path"],
            "lenght" => $data["lenght"],
            "status_code" => $data["status_code"],
            "server_host" => $data["server_host"],
            "device" => $data["device"],

        ];
        //var_dump($sql);
        //var_dump($dataTransform);
        return $this->insertTransaction($sql, $dataTransform);
    }


    public function exists($ip, $device, DateTime $accessAt)
    {
        $sql = "Select * from " .  TABLE_NAME . " where ip = :ip AND device = :device AND access_at = :access_at";

        $data = [
            "ip" => $ip,
            "device" => $device,
            "access_at" => $accessAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        $result = $stmt->setFetchMode(PDO::FETCH_ASSOC);

        return count($stmt->fetchAll())  > 0;
    }

    public function submitTransaction()
    {
        try {
            $this->pdo->commit();
        } catch(PDOException $e) {
            $this->pdo->rollBack();
            return false;
        }

          return true;
    }
}

$db = new DBTransaction();

$db->startTransaction();
//access_at
array_pop($transformData);

foreach ($transformData as $key => $preparedData) {
    if (!$db->exists($preparedData["ip"], $preparedData["device"], $preparedData["access_at"])) {
        $db->addLogAccess($preparedData);  
        var_dump($preparedData["device"]);      
    }
}
$db->submitTransaction();

var_dump($db->last_insert_id);

//last_insert_id
