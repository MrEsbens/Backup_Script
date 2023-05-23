<?php
define('USER', 'root');
define('PASSWORD', '');
class Database
{
    private static $_instance = null;
    private $connection = null;

    private function __construct()
    {
        try
        {
            $this->connection = new PDO('mysql:host=localhost;dbname=backup test', USER, PASSWORD,
            // $this->connection = new PDO('mysql:host=localhost;dbname=sitemanager', "bitrix0", "ZuMPm]CL2DdO8Hu%gVC7",
            [
                //В случае проблем выбрасывать исключение
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // по умолчанию использовать имена столбцов
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // не использовать эмуляцию подготовленных выражений средствами PDO
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } 
        catch (PDOException $e)
        {
            print "Error!: " . $e->getMessage();
            die();
        }
    }
    // запрещаем клонирование
    private function __clone(){}
    // запрещаем десериализацию
    public function __wakeup()
    {
        throw new \BadMethodCallException("Unable to deserialize database connection");
    }
    // создает экземпляр класса, хранящий подключение к БД
    public static function getInstance():Database
    {
        if(self::$_instance == null)
        {
            self::$_instance = new static();
        }
        return self::$_instance;
    }
    // экземпляр подключения к бд
    public static function connection():\PDO
    {
        return static::getInstance()->connection;
    }
    // подготовленное выражение
    public static function prepare($statement): \PDOStatement
    {
        return static::connection()->prepare($statement);
    }
    // id последней добавленной записи
    public static function lastInsertId(): int
    {
        return intval(static::connection()->lastInsertId());
    }
}


class TableInteraction
{
    public static function return_create(string $tablename)
    {
        $query=Database::prepare("SHOW CREATE TABLE $tablename");
        $query->execute();
        $res=$query->fetchAll();
        if(!count($res))
        {
            return null;
        }
        return $res;
    }
    public static function return_tables()
    {
        $query=Database::prepare("SHOW TABLES");
        $query->execute();
        $res=$query->fetchAll();
        if(!count($res))
        {
            return null;
        }
        return $res;
    }
    public static function read(string $tablename, int $offset)
    {
        $query= Database::prepare("SELECT * FROM $tablename LIMIT 100 OFFSET $offset");
        $query->execute();
        $res=$query->fetchAll();
        if(!count($res))
        {
            return null;
        }
        return $res;
    }
}


function get_db_create_tables()
{
    $selected_tables=TableInteraction::return_tables();
    $tables=[];
    foreach($selected_tables as $table)
    {
        foreach($table as $value)
        {
            $tables[]=$value;
        }
    }

    for($i=0; $i<count($tables); $i++)
    {
        $create[]=TableInteraction::return_create($tables[$i]);
    }
    return $create;
}

function whrite_create()
{
    $create=get_db_create_tables();
    $content = "";
    $bf=fopen('create.sql', 'w+') or die ("Не удалось открыть файл");
    for($i=0; $i<count($create);$i++)
    {
        $content.=$create[$i][0]['Create Table']."\n\n";
    }
    fwrite($bf,$content);
    fclose($bf);
}

function write_insert(array $location)
{
    $t_start=time();
    $create=get_db_create_tables();
    $bf=fopen('insert.sql', 'a+') or die ("Не удалось открыть файл");
    while(time()-$t_start<=1500)
    {
        $content="";
        if($location['table']>=count($create))
        {
            return null;
        }
        $values=TableInteraction::read($create[$location['table']][0]["Table"],$location['offset']);
        if($values==null)
        {
            $location['table']+=1;
            $location['offset']=0;
            continue;
        }
        for($i=0; $i<count($values);$i++)
        {
            $content.="INSERT INTO '".$create[$location['table']][0]["Table"]."' (";
            $counter=0;
            foreach($values[$i] as $key=>$value)
            {
                $counter++;
                if($counter==count($values[$i]))
                {
                    $content.="'".$key."') VALUES (";
                    $counter = 0;
                    break;
                }
                $content.="'".$key."', ";
            }
            foreach($values[$i] as $value)
            {
                $counter++;
                if($counter==count($values[$i]))
                {
                    $content.="'".$value."')\n";
                    $counter = 0;
                    break;
                }
                $content.="'".$value."', ";
            }
        }
        fwrite($bf,$content);
        $location['offset']+=100;
    }
    fclose($bf);
    return $location;
}

function refresh($location)
{
    if($location==null)
    {
        $url = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $url = explode('?', $url);
        $url = $url[0];
        $url.="?process=compress";
        header("Location: ".$url);
    }else
    {
        $url = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $url = explode('?', $url);
        $url = $url[0];
        $url.="?location=".$location['table'].":".$location['offset']."&process=export";
        header("Location: ".$url);
    }
}
function compress (string $server_directory)
{
    // $t_start=time();
    // while(time()-$t_start<=1500)
    // {
    // }
    $z = new ZipArchive();
    $res = $z -> open('test.zip', ZipArchive::CREATE);
    if($res==true)
    {
        echo "zip file created successfully <br/>";
        $z->addFile('data.txt', 'entryname.txt');
        $z->close();
    }
    else
    {
        $z->close();
        exit("can't create zip file <br/>");
    }
}


if(count($_GET)>0)
{
    if ($_GET['process']=="export")
    {
        $location=[
            'table'=>0,
            'offset'=>0,
        ];
        $tmp=explode(":",$_GET['location']);
        $location['table']=$tmp[0];
        $location['offset']=$tmp[1];
        $location=write_insert($location);
        refresh($location);
    }
    if($_GET['process']=="compress")
    {
        $server_directory = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $server_directory = explode('?', $server_directory);
        $server_directory = $server_directory[0];
        compress($server_directory);
        exit("process completed successfully");
    }
}
else{
    $location=[
        'table'=>0,
        'offset'=>0,
    ];
    whrite_create();
    $url = ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $url = explode('?', $url);
    $url = $url[0];
    $url.="?location=".$location['table'].":".$location['offset']."&process=export";
    header("Location: ".$url);
}
?>