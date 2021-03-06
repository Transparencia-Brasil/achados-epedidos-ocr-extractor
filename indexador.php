<?php

// -
// Indexador de Anexos
// Enumera os Anexos , leva elas para o Vision e indexa os textos.
// -

namespace Google\Cloud\Samples\Vision;

require __DIR__ . '/vendor/autoload.php';

use Alchemy\Zippy\Zippy;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Vision\V1\AnnotateFileRequest;
use Google\Cloud\Vision\V1\AnnotateFileResponse;
use Google\Cloud\Vision\V1\AsyncAnnotateFileRequest;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\GcsDestination;
use Google\Cloud\Vision\V1\GcsSource;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\InputConfig;
use Google\Cloud\Vision\V1\OutputConfig;

use Symfony\Component\Dotenv\Dotenv;
use ZipArchive;

$dotenv = new Dotenv();
$dotenv->load(__DIR__.'/.env');

define("FILES_PATH", $_ENV["FILES_PATH"]);
define("BUCKET_PATH", $_ENV["BUCKET_PATH"]);
define("API_URL", $_ENV["API_URL"]);
ini_set('memory_limit', '1024M'); 

/**
 * Bloqueio para Impedir que a Tarefa seja Executada mais de uma Vez
 */
class TaskLocker
{
    private $fp;

    public function Bloquear($id)
    {
        $uploadsDir = "/tmp/";
        $arqlock = $uploadsDir . $id . ".lock";
        
        if (file_exists($uploadsDir) === false) {
            mkdir($uploadsDir, 0777, true);
        }

        $this->fp = fopen($arqlock, "w+");

        if (!$this->fp) {
            return false;
        }

        $count = 0;
        $timeout_secs = 10;
        $got_lock = true;
        while (!flock($this->fp, LOCK_EX | LOCK_NB, $wouldblock)) {
            if ($wouldblock && $count++ < $timeout_secs) {
                sleep(1);
            } else {
                $got_lock = false;
                break;
            }
        }

        if ($got_lock) {
            fwrite($this->fp, "TASK_RUNNING\n");
            fflush($this->fp);
            return true;
        } else {
            return false;
        }
    }

    public function Liberar()
    {
        flock($this->fp, LOCK_UN);
    }

    public function Fechar()
    {
        fclose($this->fp);
    }
}


/**
 * Indexador de Anexos
 */
class PedidoAnexoIndexador
{
    private $rltCaminho;
    private $DbConn;
    private $ApiClient;
    private $LogAtual;

    # Explicitly use service account credentials by specifying the private key
    # file.
    private $googleVisionCredentials;
    private $googleStorageCredentials;

    public function Init()
    {
        $this->googleStorageCredentials = [
            'keyFilePath' => $_ENV["GOOGLE_APPLICATION_CREDENTIALS"]
        ];
                
        $this->googleVisionCredentials = [
            'credentials' => $_ENV["GOOGLE_APPLICATION_CREDENTIALS"]
        ];

		$this->Conectar();
	
        $rendererName = \PhpOffice\PhpWord\Settings::PDF_RENDERER_DOMPDF;
        $rendererLibraryPath = realpath(__DIR__ . '/vendor/dompdf/dompdf');
        \PhpOffice\PhpWord\Settings::setPdfRenderer($rendererName, $rendererLibraryPath);

        $this->ApiClient = new \GuzzleHttp\Client([
            'base_uri' => $_ENV['API_URL'],
            'timeout'  => 60.0,
        ]);
    }
	
	protected function Desconectar() {
        mysqli_close($this->DbConn);
    }
	
	protected function Reconectar() {
		$this->Desconectar();
		$this->Conectar();
	}
	
	protected function Conectar() {
		if (isset($_ENV['MYSQL_ATTR_SSL_CA']) && !empty($_ENV['MYSQL_ATTR_SSL_CA'])) {
            $this->DbConn = mysqli_init();
            $this->DbConn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
            $this->DbConn->ssl_set(NULL, NULL, $_ENV['MYSQL_ATTR_SSL_CA'], NULL, NULL);
            $this->DbConn->real_connect($_ENV['DB_HOST'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $_ENV['DB_DATABASE']);
        } else {
            $this->DbConn =  new \mysqli($_ENV['DB_HOST'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $_ENV['DB_DATABASE']);
            if ($this->DbConn->connect_error) {
                die("DB Connection failed: " . $this->DbConn->connect_error . "\n");
            }
         }

		$this->DbConn->query("SET NAMES 'utf8'");
        $this->DbConn->query('SET character_set_connection=utf8');
        $this->DbConn->query('SET character_set_client=utf8');
        $this->DbConn->query('SET character_set_results=utf8');
	}

    /**
     * Realiza a Contagem de Anexos Pendentes para a Anal??se no VISION
     */
    public function ContarAnexos($somenteAnexo = NULL)
    {
        $result = NULL;
        
        if (is_null($somenteAnexo)) {
            $result = $this->DbConn->query("Select COUNT(*) as Cnt From pedidos_anexos Where CodigoStatusExportacaoES = 'esperando' ");
        } else {
            $result = $this->DbConn->query("Select COUNT(*) as Cnt From pedidos_anexos Where Codigo = $somenteAnexo");
        }

        if ($result->num_rows > 0) {
            return $result->fetch_assoc()["Cnt"];
        } else {
            return 0;
        }
    }

    /**
     * Atualiza um Anexo
     */
    public function AtualizarEstadoAnexo($codigo, $estado)
    {
        $result = $this->DbConn->query("Update pedidos_anexos Set CodigoStatusExportacaoES = '$estado', Alteracao = NOW() Where Codigo =  $codigo");
        return $result;
    }

    /**
     * Realiza a Contagem de Anexos Pendentes para a Anal??se no VISION
     */
    public function BuscarAnexos($limite, $pular, $somenteAnexo = NULL)
    {
		//auto reconnect if MySQL server has gone away
        if (!mysqli_ping($this->DbConn)) $this->Reconectar();
		
        if (is_null($somenteAnexo)) {
            // And Codigo NOT IN (3154, 3039, 3040, 12387, 10874, 10110, 10368, 10369, 10370, 10348, 10094,10502, 10524 , 10839, 10589, 10336, 10338, 10341, 10344, 9765, 9513, 10806, 10300, 10566, 9545, 11720, 11984, 11495, 12009, 12018, 12029, 12042, 11825, 11844, 11596, 11599, 12113, 12128, 11703, 11704, 12257,12226,154566,154563,154902,154420,154411,154361,154902,26279,26822,13915 ,26810,26800,26749,26707,26704,26459,26157,26158,26159,26140,26110,25922,25623,25555,22838 ) 
            $result = $this->DbConn->query("Select * From pedidos_anexos Where CodigoStatusExportacaoES = 'esperando' Order By Criacao DESC LIMIT $limite OFFSET $pular");
        } else {
            $result = $this->DbConn->query("Select * From pedidos_anexos Where Codigo = $somenteAnexo");
        }
        return $result;
    }

    protected function _processarDocx($gsPath, $lcPath, $caminho, $gsOutput)
    {
        $this->AddLog("Anexo precisa ser convertido!");
        $caminhoNovo="ocr_indexador/convertidos/atual.pdf";
        $lcOutput = FILES_PATH . "$caminhoNovo";

        $this->AddLog("$lcPath " . " -> convertendo... -> " . $lcOutput);

        $phpWord = \PhpOffice\PhpWord\IOFactory::load($lcPath);
        $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'PDF');
        $objWriter->save($lcOutput);

        $gsPath = BUCKET_PATH."/$caminhoNovo";
        $this->AddLog("Convertido");

        // -------------------------------------
        return $this->detect_pdf_gcs($lcOutput, $gsOutput, "application/pdf");
    }

    protected function _processarXls($gsPath, $lcPath, $caminho, $gsOutput)
    {
        $texto = "";
        $this->AddLog("$lcPath " . " -> analisando... ");

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($lcPath);

        $cellCount = 0;
		$rowCount = 0;
        $sheetCount = $spreadsheet->getSheetCount();		
		
        $this->AddLog(" $sheetCount planinhas h?? serem analisadas");
		
		$pularExistCells =false;
		
        for ($i = 0; $i < $sheetCount; $i++) {
            $worksheet = $spreadsheet->getSheet($i);			
			$qtdLinhasBranco = 0;
			
            foreach ($worksheet->getRowIterator() as $row) {				
                $rowTexto = "";
                $cellIterator = $row->getCellIterator();
                
				if(!$pularExistCells) {
					try {
						$cellIterator->setIterateOnlyExistingCells(true);
					} catch (PHPExcel_Exception $e) {
						$this->AddLog("N??o foi possivel determinar o fim da planinha, analise poder?? ser lenta.");
						$pularExistCells = true;
					}
					catch (\Exception $e) {
						$this->AddLog("N??o foi possivel determinar o fim da planinha, analise poder?? ser lenta.");
						$pularExistCells = true;
					}
				}
				
				$cellsLinhaAtualCount = 0;
                foreach ($cellIterator as $cell) {
                    $rowTexto .= $cell->getValue() . " ";
                    $cellCount ++;
					$cellsLinhaAtualCount ++;
                }

				if($cellsLinhaAtualCount == 0 || strlen(trim($rowTexto)) <= 0) {
					$qtdLinhasBranco++;
				}
				
                $texto .= $rowTexto . PHP_EOL;
				$rowCount++;
				echo "$sheetCount/$rowCount" . PHP_EOL ;
				
				if($qtdLinhasBranco > 1000) {
					$this->AddLog("Muitas Linhas em Branco Encontradas, CONSIDERANDO FIM DE PLANINHA.");
					break;
				}
            }
        }

        $this->AddLog("$cellCount celulas analisadas, texto: " . strlen($texto));

        $spreadsheet = null;
        return $texto;
    }

    protected function _processarImgs($gsPath, $lcPath, $caminho, $gsOutput)
    {
        $this->AddLog("Anexo precisa ser convertido!");
        $caminhoNovo="ocr_indexador/convertidos/atual.tiff";
        $lcOutput =FILES_PATH . "$caminhoNovo";

        $this->AddLog("$lcPath " . " -> convertendo... -> " . $lcOutput);

        $image = new \Imagick($lcPath);
        $image->writeImage($lcOutput);

        $gsPath = BUCKET_PATH."/$caminhoNovo";
        $this->AddLog("Convertido");

        // -------------------------------------
        return $this->detect_pdf_gcs($lcOutput, $gsOutput, "image/tiff");
    }

    private function cleanUpOcrDir()
    {
        $files = glob(FILES_PATH . 'ocr_indexador/convertidos/*');
        foreach ($files as $file) { // iterate files
            if (is_file($file)) {
                unlink($file); // delete file
            }
        }

        $files = glob(FILES_PATH . 'ocr_indexador/*');
        foreach ($files as $file) { // iterate files
            if (is_file($file)) {
                unlink($file); // delete file
            }
        }
    }

    protected function AtualizarAnexoConteudo($row, $textos, $caminho, $ext)
    {
        echo "Indexando: " . $caminho. PHP_EOL;
        
        $reqBody = array(
            "anexos_conteudo_arquivo" => $textos
        );

        $uri = API_URL . "/anexos/extractor-update/" . $row["Codigo"];
        
        try {
            $this->AddLog("Indexando: " . $uri);
    //        $this->AddLog("Conteudo: " . $textos);

            $r = $this->ApiClient->request('PUT', $uri, [
                'json' => mb_convert_encoding( $reqBody, 'UTF-8', 'UTF-8')
            ]);

            // -
            $this->AddReportEntry($row["Codigo"],$caminho, $ext, strlen($textos) . " caracteres foram indexados");
            $this->AddLog("Anexo Indexado!");

            return true;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->AddLog("A API n??o retornou sucesso:");
            $this->AddLog(\GuzzleHttp\Psr7\str($e->getRequest()));

            if ($e->hasResponse()) {
                $this->AddLog(\GuzzleHttp\Psr7\str($e->getResponse()));
            }
            
            // -
            $this->AddLog("A Indexa????o falhou!");
            $this->AddReportEntry($row["Codigo"],$caminho, $ext, strlen($textos) . " caracteres n??o foram indexados");

            return false;
        }
    }

    protected function initLogs($arquivo)
    {
        $pastaLogs = FILES_PATH . "ocr_indexador/logs/" . date('d-m-Y-H-i');
        if (!file_exists($pastaLogs)) {
            mkdir($pastaLogs, 0777, true);
        }

        $this->LogAtual = "$pastaLogs/$arquivo.log";
    }

    protected function InitAnexo($row, &$caminho, &$codigo, &$ext) {
        $caminho = mb_convert_encoding($row["Arquivo"], "UTF-8");
        $codigo = $row["Codigo"];        
        $caminhoParts = pathinfo($caminho);
        $ext = trim(strtoupper($caminhoParts['extension']));
        $this->initLogs($caminhoParts['filename']);
    }

    protected function ExtrairRar($fonte, $destino) {
        // Extrai o Conteudo
        $this->AddLog("Extraindo o Arquivo Compactado: $fonte");
        if(!file_exists($destino)) {
            mkdir($destino);
        }

        shell_exec(__DIR__ . "/rar/unrar x \"$fonte\" \"$destino\""); 
    }

    protected function ExtrairZip($fonte, $destino) {
        // Extrai o Conteudo do Zip e Realiza a Analise de Cada Arquivo     
        $zippy = Zippy::load();        
        $zip = $zippy->open($fonte);

        // Extrai o Conteudo
        $this->AddLog("Extraindo o Arquivo Compactado: $fonte");
        if(!file_exists($destino)) {
            mkdir($destino);
        }

        $zip->extract($destino);
    }

    protected function AnalisarCompactado($caminho, $ext, $codigo, $row)
    {
        // =
        $textos = "";

        $zipPath = FILES_PATH . "pedidos/$caminho"; 

        $this->AddLog("Analisando o Arquivo Compactado: $caminho");

        // - Verifica se existe
        if (file_exists($zipPath) === false) {
            $this->AtualizarEstadoAnexo($codigo, "falha");
            $this->AddReportEntry($codigo,$caminho, $ext, "N??o existe!");
            $this->AddLog("Anexo n??o encontrado");
            echo "N??o existe!";
            return;
        }

        $tmpContentsFolder = $zipPath . "_archive/";
        if($ext == "RAR") {
            $this->ExtrairRar($zipPath, $tmpContentsFolder);
        } else {
            $this->ExtrairZip($zipPath, $tmpContentsFolder);
        }

        //
        $this->AddLog("Analisando os Arquivos Extraidos: $tmpContentsFolder");
        $dir = new \DirectoryIterator($tmpContentsFolder);
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }

            $caminhoArq = str_replace(FILES_PATH . "pedidos/", "", $tmpContentsFolder . basename($fileinfo->getFilename()));
            $caminhoArqParts = pathinfo($caminhoArq);
            $extArq = trim(strtoupper($caminhoArqParts['extension']));

            $this->AddLog("Analisando: $caminhoArq");
            $textos .= $this->Analisar($caminhoArq, $extArq, $codigo, $row);                
        }

        return $textos;
    }

    protected function Analisar($caminho, $ext, $codigo, $row)
    {
        // =
        $textos = "";

        // -
        $gsPath = BUCKET_PATH ."/pedidos/$caminho";
        $lcPath = FILES_PATH . "pedidos/$caminho";  

        $this->AddLog("Caminho: " . $lcPath);
        
        // - Verifica se existe
        if (file_exists($lcPath) === false) {
            $this->AtualizarEstadoAnexo($codigo, "falha");
            $this->AddReportEntry($codigo,$caminho, $ext, "N??o existe!");
            $this->AddLog("Anexo n??o encontrado");
            echo "N??o existe!";
            return;
        }

        // -
        $gsOutput = BUCKET_PATH."/ocr_indexador/";

        // -------------------------------------
        // - Converte o Anexo em PDF
        // - DOCX
        if ($ext === "DOCX" || $ext === "DOC" || $ext === "RTF" || $ext === "ODF" || $ext === "ODT") {
            $this->AddLog("Avaliando como DOCX");
            $textos = $this->_processarDocx($gsPath, $lcPath, $caminho, $gsOutput);
        }
        // - EXCEL
        elseif ($ext === "XLSX" || $ext === "XLS" || $ext === "ODS"  || $ext === "CSV") {
            $this->AddLog("Avaliando como EXCEL");
            $textos = $this->_processarXls($gsPath, $lcPath, $caminho, $gsOutput);
        } elseif ($ext === "GIF" || $ext === "JPG" || $ext === "JPEG" || $ext === "PNG") {
            $this->AddLog("Avaliando como TIFF");
            $textos = $this->_processarImgs($gsPath, $lcPath, $caminho, $gsOutput);
        }
        // - TEXTO DIRETO
        elseif ($ext === "TXT") {
            $this->AddLog("Avaliando como TXT");
            $textos = file_get_contents($lcPath);
        }
        elseif ($ext === "PDF") {
            // -------------------------------------
            $this->AddLog("Avaliando como PDF");
            $textos = $this->detect_pdf_gcs($lcPath, $gsOutput, "application/pdf");
        }
        elseif ($ext === "WAV" || $ext === "MP3" || $ext === "MP4") {
            // -------------------------------------
            $this->AddLog("Nao e possivel analisar arquivos de audio/video");
        }
        else { // Tenta Passar Pelo VISION
            // -------------------------------------
            $this->AddLog("Avaliando como TIFF");
            $textos = $this->detect_pdf_gcs($lcPath, $gsOutput, "image/tiff");
        }

        return $textos;
    }

    /**
     * Processa o Anexo e Indexa os Textos Relacionados
     */
    public function ProcessarAnexo($row)
    {
        $this->cleanUpOcrDir();
        $caminho = "";
        $ext = "";
        $codigo = 0;
        $this->InitAnexo($row, $caminho, $codigo, $ext);
        $this->AddLog("Iniciando o processamento: \r\n C??digo: $codigo ");
        
        $textos = "";

        if($ext == "ZIP" || $ext == "TAR" || $ext == "TAR.GZ" || $ext == "RAR") {
            $textos = $this->AnalisarCompactado($caminho, $ext, $codigo, $row);
        } else {
            $textos = $this->Analisar($caminho, $ext, $codigo, $row);
        }

        // -
        if (strlen($textos) > 0) {
            if ($this->AtualizarAnexoConteudo($row, $textos, $caminho, $ext)) {
                $this->AtualizarEstadoAnexo($codigo, "extraido");
            } else {
                $this->AtualizarEstadoAnexo($codigo, "falha");
            }
        } else {
            // -
            $this->AddReportEntry($codigo,$caminho, $ext, "Resultou em: 0 caracteres");
            $this->AddLog("Anexo resultou em 0 Caracters");
            $this->AtualizarEstadoAnexo($codigo, "falha");
        }
    }

    /**
     * Executa o Algoritmo de Indexa????o dos Anexos com o Vision
     */
    public function Run($somenteAnexo = NULL)
    {
        echo "PROC_INICIO_OK";
        $QTD_POR_LOTE = 100; // Ajusta a Quantidade de Anexos que ir??o ser processados por Lote.
        $CntAnexos = 0;

        // -
        $this->rltCaminho = FILES_PATH  . "RltIndexador-" . date('d-m-Y-H')  . ".csv";
        echo "Relat??rio ser?? gerado em:" . $this->rltCaminho . PHP_EOL;

        file_put_contents($this->rltCaminho, "sep=,\r\n");
        file_put_contents($this->rltCaminho, "Horario,CodigoPedidoAnexo,Arquivo,Extensao,Mensagem\r\n", FILE_APPEND);
        

        // -
        $CntAnexos = $this->ContarAnexos($somenteAnexo);
        echo "Anexos h?? processar: $CntAnexos" . PHP_EOL;

        if ($CntAnexos > 0) {
            $this->AddReportEntry("","", "", "Contagem: $CntAnexos");

            $qtdLotes = ceil($CntAnexos / $QTD_POR_LOTE);
            echo "Lotes: " . $qtdLotes . PHP_EOL;

            for ($iLote=0; $iLote < $qtdLotes ; $iLote++) {
                $pPular = $iLote * $QTD_POR_LOTE;

                // Pesquisa o Lote
                echo "Fetch Lote: $iLote = $pPular / $QTD_POR_LOTE" . PHP_EOL;
                $result = $this->BuscarAnexos($QTD_POR_LOTE, $pPular, $somenteAnexo);
				
				if($result === false) {
					echo "ERRO AO RECUPERAR O LOTE: " . $this->DbConn->error . PHP_EOL;
					die();
				}

                // Processa o Lote
                while ($row = $result->fetch_assoc()) {
                    if(is_null($somenteAnexo)) {
                        $codigo = $row["Codigo"];  
                        $caminho = "";
                        $ext = "";

                        $CMD = "cd " . __DIR__ . " && php indexador.php $codigo  2>&1";

                        $this->InitAnexo($row, $caminho, $codigo, $ext);
                        $this->AddLog("Iniciando para $codigo / $CMD");

                        // Inicia o Processo para INDEXAR
                        ob_start();
                        $resultado = shell_exec($CMD);
                        ob_end_clean();

                        $this->AddLog($resultado);
                        if(strpos($resultado, "PROC_FINAL_OK") === false) {
                            $this->AddLog("Indexacao FALHOU: $codigo");
                            $this->AddReportEntry($codigo,"", "", "Erro no Processamento:  Processo de Indexa????o falhou!" );
                            $this->AtualizarEstadoAnexo($codigo, "falha");
                        } else {                            
                            $this->AddLog("Indexacao OK: $codigo");
                        }
                    }
                    else {
                        try {                                                
                            $this->ProcessarAnexo($row);
                        } catch (Exception $e) {
                            echo "Erro no processamento do anexo: ";
                            print($e->getMessage());
                            echo PHP_EOL;
                            $codigo = $row["Codigo"];
                            $this->AddReportEntry($codigo,"", "", "Erro no Processamento: " . $e->getMessage());
                            $this->AtualizarEstadoAnexo($codigo, "falha");
                        }
                    }
                }
            }
        }

        // -
        echo "PROC_FINAL_OK";
    }
    
    protected function AddReportEntry($codigo,$arquivo, $ext, $msg)
    {
        file_put_contents($this->rltCaminho, date('d-m-Y H:i:s') . "," . $codigo .  "," . $arquivo . "," . $ext . "," . $msg . "\r\n", FILE_APPEND);
    }
     
    protected function AddLog($msg)
    {
        file_put_contents($this->LogAtual, date('d-m-Y H:i:s') . " " . $msg . "\r\n", FILE_APPEND);
        echo $msg . PHP_EOL;
    }

    protected function detect_pdf_gcs($path, $output, $mimeType)
    {
        $textoResultado = "";

        try {

            $this->AddLog("Vision: $path");

        # select ocr feature
            $feature = (new Feature())
        ->setType(Type::DOCUMENT_TEXT_DETECTION);

            # set $path (file to OCR) as source
            //$gcsSource = (new GcsSource())
        ///->setUri($path);

            $inputConfig = (new InputConfig())
        //->setGcsSource($gcsSource)
        ->setContent(file_get_contents($path))
        ->setMimeType($mimeType);

            # set $output as destination
            $gcsDestination = (new GcsDestination())
        ->setUri($output);

            # how many pages should be grouped into each json output file.
            $batchSize = 10;
            $outputConfig = (new OutputConfig())
        ->setGcsDestination($gcsDestination)
        ->setBatchSize($batchSize);

            # prepare request using configs set above
            $request = (new AnnotateFileRequest())
        ->setFeatures([$feature])
        ->setInputConfig($inputConfig);
            $requests = [$request];

            # make request
            $imageAnnotator = new ImageAnnotatorClient($this->googleVisionCredentials);
            $result = $imageAnnotator->batchAnnotateFiles($requests)->getResponses();
            $pages = $result->count();
            
            # get annotation and print text
            for ($iPagina=0; $iPagina < $pages; $iPagina++) {
                $firstBatch = $result->offsetGet($iPagina);
                foreach ($firstBatch->getResponses() as $response) {
                   // print_r($response);
                    $annotation = $response->getFullTextAnnotation();
                    if ($annotation !== null) {
                        $texto = $annotation->getText();
                        $textoResultado .= "$texto" . PHP_EOL;
                    }
                }
            }   
           
            $imageAnnotator->close();
        } catch (\Exception $e) {
            $textoResultado = "";
            $this->AddLog("VisionAPI Error: " . $e->getMessage());
        }

        return $textoResultado;
    }
}

function Executar($arg) {
    $indexador = new PedidoAnexoIndexador();
    $indexador->Init();
    $indexador->Run($arg);
}

// **************************************************************** ///
if (!isset($argv[1])) {
    $locker = new TaskLocker();
    if ($locker->Bloquear("PedidosAnexoIndexador")) {
        try {
            Executar(null);
        } catch (Exception $e) {
            echo "Ocorreu um erro na execu????o: " . $e->getMessage();
        }

        $locker->Liberar();
    }

    $locker->Fechar();
} else {
    echo "Processo n??o bloqueado!\r\n";
    Executar($argv[1]);
}