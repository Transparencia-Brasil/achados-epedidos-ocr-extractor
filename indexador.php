<?php

// -
// Indexador de Anexos
// Enumera os Anexos , leva elas para o Vision e indexa os textos.
// -

namespace Google\Cloud\Samples\Vision;

require __DIR__ . '/vendor/autoload.php';

use Google\Cloud\Storage\StorageClient;
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

$dotenv = new Dotenv();
$dotenv->load(__DIR__.'/.env');

define("FILES_PATH", $_ENV["FILES_PATH"]);
define("BUCKET_PATH", $_ENV["BUCKET_PATH"]);
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

    public function Init()
    {
        $this->DbConn =  new \mysqli($_ENV['DB_HOST'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $_ENV['DB_DATABASE']);
        if ($this->DbConn->connect_error) {
            die("DB Connection failed: " . $this->DbConn->connect_error . "\n");
        }

        $rendererName = \PhpOffice\PhpWord\Settings::PDF_RENDERER_DOMPDF;
        $rendererLibraryPath = realpath(__DIR__ . '/vendor/dompdf/dompdf');
        \PhpOffice\PhpWord\Settings::setPdfRenderer($rendererName, $rendererLibraryPath);

        $this->ApiClient = new \GuzzleHttp\Client([
            'base_uri' => $_ENV['API_URL'],
            'timeout'  => 60.0,
        ]);
    }

    /**
     * Realiza a Contagem de Anexos Pendentes para a Analíse no VISION
     */
    public function ContarAnexos()
    {
        $result = $this->DbConn->query("Select COUNT(*) as Cnt From pedidos_anexos Where CodigoStatusExportacaoES = 'esperando'");

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
     * Realiza a Contagem de Anexos Pendentes para a Analíse no VISION
     */
    public function BuscarAnexos($limite, $pular)
    {
        $result = $this->DbConn->query("Select * From pedidos_anexos Where CodigoStatusExportacaoES = 'esperando' Order By Criacao DESC LIMIT $limite OFFSET $pular");
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

        $gsPath = BUCKET_PATH.$caminhoNovo;
        $this->AddLog("Convertido");

        // -------------------------------------
        return $this->detect_pdf_gcs($gsPath, $gsOutput, "application/pdf");
    }

    protected function _processarXls($gsPath, $lcPath, $caminho, $gsOutput)
    {
        $texto = "";
        $this->AddLog("$lcPath " . " -> analisando... ");

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($lcPath);

        $cellCount = 0;

        $sheetCount = $spreadsheet->getSheetCount();
        for ($i = 0; $i < $sheetCount; $i++) {
            $worksheet = $spreadsheet->getSheet($i);
            foreach ($worksheet->getRowIterator() as $row) {
                $rowTexto = "";
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(TRUE);
                foreach ($cellIterator as $cell) {
                    $rowTexto .= $cell->getValue() . " ";
                    $cellCount += 1;
                }

                $texto .= $rowTexto . PHP_EOL;
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

        $gsPath = BUCKET_PATH.$caminhoNovo;
        $this->AddLog("Convertido");

        // -------------------------------------
        return $this->detect_pdf_gcs($gsPath, $gsOutput, "image/tiff");
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
        echo "Indexando: " . $row["Arquivo"] . PHP_EOL;
        
        $reqBody = array(
            "anexos_conteudo_arquivo" => $textos
        );

        $uri = "http://www.achadosepedidos.org.br:8080/api/anexos/extractor-update/" . $row["Codigo"];
        
        try {
            $this->AddLog("Indexando: " . $uri);
            $this->AddLog("Conteudo: " . $textos);

            $r = $this->ApiClient->request('PUT', $uri, [
                'json' => $reqBody
            ]);

            // -
            $this->AddReportEntry($row["Codigo"],$caminho, $ext, strlen($textos) . " caracteres foram indexados");
            $this->AddLog("Anexo Indexado!");

            return true;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->AddLog("A API não retornou sucesso:");
            $this->AddLog(\GuzzleHttp\Psr7\str($e->getRequest()));

            if ($e->hasResponse()) {
                $this->AddLog(\GuzzleHttp\Psr7\str($e->getResponse()));
            }
            
            // -
            $this->AddLog("A Indexação falhou!");
            $this->AddReportEntry($row["Codigo"],$caminho, $ext, strlen($textos) . " caracteres não foram indexados");

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

    /**
     * Processa o Anexo e Indexa os Textos Relacionados
     */
    public function ProcessarAnexo($row)
    {
        $this->cleanUpOcrDir();
        $caminho = $row["Arquivo"];
        $codigo = $row["Codigo"];
        $caminhoParts = pathinfo($caminho);
        $ext = trim(strtoupper($caminhoParts['extension']));
        $this->initLogs($caminhoParts['filename']);

        $this->AddLog("Iniciando o processamento: \r\n Código: $codigo ");
        
        // =
        $textos = "";

        // -
        $gsPath = BUCKET_PATH."pedidos/$caminho";
        $lcPath = FILES_PATH . "pedidos/$caminho";
        
        $this->AddLog("Caminho: " . $lcPath);

        // - Verifica se existe
        if (file_exists($lcPath) === false) {
            $this->AtualizarEstadoAnexo($codigo, "falha");
            $this->AddReportEntry($codigo,$caminho, $ext, "Não existe!");
            $this->AddLog("Anexo não encontrado");
            echo "Não existe!";
            return;
        }

        // -
        $gsOutput = BUCKET_PATH."ocr_indexador/";

        // -------------------------------------
        // - Converte o Anexo em PDF
        // - DOCX
        if ($ext === "DOCX" || $ext === "DOC" || $ext === "RTF" || $ext === "ODF") {
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
            $textos = $this->detect_pdf_gcs($gsPath, $gsOutput, "application/pdf");
        }
        else { // Tenta Passar Pelo VISION
            // -------------------------------------
            $this->AddLog("Avaliando como TIFF");
            $textos = $this->detect_pdf_gcs($gsPath, $gsOutput, "image/tiff");
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
     * Executa o Algoritmo de Indexação dos Anexos com o Vision
     */
    public function Run()
    {
        $QTD_POR_LOTE = 100; // Ajusta a Quantidade de Anexos que irão ser processados por Lote.

        // -
        $this->rltCaminho = FILES_PATH  . "/RltIndexador-" . date('d-m-Y-H-i')  . ".csv";
        echo "Relatório será gerado em:" . $this->rltCaminho . PHP_EOL;

        file_put_contents($this->rltCaminho, "sep=,\r\n");
        file_put_contents($this->rltCaminho, "Horario,CodigoPedidoAnexo,Arquivo,Extensao,Mensagem\r\n", FILE_APPEND);


        // -
        $CntAnexos = $this->ContarAnexos();

        echo "Anexos há processar: $CntAnexos" . PHP_EOL;

        if ($CntAnexos > 0) {
            $this->AddReportEntry("","", "", "Contagem: $CntAnexos");

            $qtdLotes = ceil($CntAnexos / $QTD_POR_LOTE);
            for ($iLote=0; $iLote < $qtdLotes ; $iLote++) {
                $pPular = $iLote * $QTD_POR_LOTE;

                // Pesquisa o Lote
                echo "Fetch Lote: $iLote = $pPular / $QTD_POR_LOTE" . PHP_EOL;
                $result = $this->BuscarAnexos($QTD_POR_LOTE, $pPular);

                // Processa o Lote
                while ($row = $result->fetch_assoc()) {
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

        # select ocr feature
            $feature = (new Feature())
        ->setType(Type::DOCUMENT_TEXT_DETECTION);

            # set $path (file to OCR) as source
            $gcsSource = (new GcsSource())
        ->setUri($path);

            $inputConfig = (new InputConfig())
        ->setGcsSource($gcsSource)
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
            $request = (new AsyncAnnotateFileRequest())
        ->setFeatures([$feature])
        ->setInputConfig($inputConfig)
        ->setOutputConfig($outputConfig);
            $requests = [$request];

            # make request
            $imageAnnotator = new ImageAnnotatorClient();
            $operation = $imageAnnotator->asyncBatchAnnotateFiles($requests);
            print('Waiting for operation to finish.' . PHP_EOL);
            $operation->pollUntilComplete();

            if ($operation->operationSucceeded()) {
                # once the request has completed and the output has been
                # written to GCS, we can list all the output files.
                preg_match('/^gs:\/\/([a-zA-Z0-9\._\-]+)\/?(\S+)?$/', $output, $match);
                $bucketName = $match[1];
                $prefix = isset($match[2]) ? $match[2] : '';

                $storage = new StorageClient();
                $bucket = $storage->bucket($bucketName);
                $options = ['prefix' => $prefix];
                $objects = $bucket->objects($options);

                # Search for the Output Json
                $objects->next();
                $firstObject = null;

                # list objects with the given prefix.
                print('Output files:' . PHP_EOL);
                foreach ($objects as $object) {
                    $name = $object->name();
                    if (strpos($name, 'json') > 0 && strpos($name, 'output') >= 0) {
                        $firstObject = $object;
                        print($name . PHP_EOL);
                    }
                }

                if ($firstObject !== null) {
                    # process the first output file from GCS.
                    # since we specified batch_size=2, the first response contains
                    # the first two pages of the input file.
                    $jsonString = $firstObject->downloadAsString();
                    $firstBatch = new AnnotateFileResponse();
                    $firstBatch->mergeFromJsonString($jsonString);

                    # get annotation and print text
                    foreach ($firstBatch->getResponses() as $response) {
                        $annotation = $response->getFullTextAnnotation();
                        if ($annotation !== null) {
                            $texto = $annotation->getText();
                            $textoResultado .= "$texto" . PHP_EOL;
                        }
                    }
                } else {
                    $this->AddLog("VisionAPI não retornou nenhum objeto! ");
                }
            } else {
                $error = $operation->getError();
               
		        $this->AddLog("VisionAPI Error: " .  print_r($error, true));
            }
        
            $imageAnnotator->close();
        } catch (Exception $e) {
            $textoResultado = "";
            $this->AddLog("VisionAPI Error: " . $e->getMessage());
        }

        return $textoResultado;
    }
}

// **************************************************************** ///
$locker = new TaskLocker();
if ($locker->Bloquear("PedidosAnexoIndexador")) {
    try {
        $indexador = new PedidoAnexoIndexador();
        $indexador->Init();
        $indexador->Run();
    } catch (Exception $e) {
        echo "Ocorreu um erro na execução: " . $e->getMessage();
    }

    $locker->Liberar();
}

$locker->Fechar();
