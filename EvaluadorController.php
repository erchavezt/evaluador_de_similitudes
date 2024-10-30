<?php

namespace App\Http\Controllers\Constancia_novedades;

use DB;
use Log;
use View;
use Session;
use StdClass;
use Response;
use Dompdf\Dompdf;
use Carbon\Carbon;
use \NumberFormatter;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use App\Models\baseModel;
use App\Models\Backend\Rol;
use App\Models\Backend\UsuarioModuloRol;
use App\Models\Constancia_novedades\Solicitante;
use App\Models\Constancia_novedades\Solicitud;
use App\Models\Constancia_novedades\Solicitud_seguimiento;
use App\Models\Constancia_novedades\Resolucion;
use App\Models\Constancia_novedades\Parametro;
use App\Models\Backend\CatalogoItem;


class FunsController extends Controller
{
    public function depurar_nombre($nombre) {        
        $carex = trim(config('constantes.caracteres_especiales'));
        for($i=0; $i<strlen($carex); $i++){
            $nombre = str_replace(substr($carex,$i,1),'',$nombre);
        } 
/*         // elimino "
        $nombre = str_replace('"','',$nombre);
        // elimino '
        $nombre = str_replace("'",'',$nombre);
        // elimino -
        $nombre = str_replace("-",'',$nombre);
        // elimino (
        $nombre = str_replace("(",'',$nombre);
        // elimino )
        $nombre = str_replace(")",'',$nombre);                
*/
        return $nombre;
    }

    // ===========================================================================================================================
    // DETERMINA EL NIVEL DE ACIERTO (PROBALIDAD DE COINCIDENCIA) DE UNA PROPUESTA DE NOMBRE CON RELACIÓN A UN NOMBRE DETERMINADO
    // $nombre = nombre existente
    // $propuesta = propuesta de nombre
    // ===========================================================================================================================
    public function nivel_acierto($nombre, $propuesta) {
        $nombre    = self::depurar_nombre($nombre);
        $propuesta = self::depurar_nombre($propuesta);
        $nombres     = explode(" ",$nombre);
        $palabras    = explode(" ",$propuesta);
        $peso        = (count($nombres) * 100) / count($palabras);
        $acierto     = 0;
        $ocurrencias = [];
        if ($nombre != '') {
            for ($index = 0; $index < count($palabras); $index++) {
                $ocurrencia    = $palabras[$index] != ""?(((substr_count($nombre,$palabras[$index]) / count($nombres)) * 100) * $peso) / 100:0;
                $ocurrencias[] = $ocurrencia;            
                $acierto = $acierto + $ocurrencia;
            }   
        }
        $resultado = array_combine($palabras,$ocurrencias);
        return round($acierto,1);
    }

    // ==============================================================================================================================================
    // BUSAR SIMILITUDES
    // ==============================================================================================================================================
    public function buscar_similitudes($nombre,$id_tipo_gestion,$etapa) {
        $gestiones = CatalogoItem::where('id_catalogo',Parametro::where('parametro_nombre','TIPO DE GESTION')->value('parametro_valor'))->orderby('descripcion','ASC')->get();
        $condicion    = [];
        if (($id_tipo_gestion == 1) or ($id_tipo_gestion == 2)) {
            $condicion[] = $id_tipo_gestion;
        }

        $tipo_gestion = [];
        foreach ($gestiones as $index => $item) {
            $registro = array_map('strval',explode(' ',$item->descripcion)); 
            $tipo_gestion = array_merge($tipo_gestion,$registro);
            if (($id_tipo_gestion > 2) && ($item->id_catalogo_item > 2)) {
                $condicion[] = $item->catalogo_item;
            }
        }
        for ($i = 0; $i < count($tipo_gestion); ++$i){
            $tipo_gestion[$i] = baseModel::quitarTildes($tipo_gestion[$i]);
        }
        $estados_validos = [];
        if ($etapa == 1) {
            $id_etapa = config('constantes.etapa_analisis');            
            if (config('constantes.estados_validos_analisis') != "") {
                $estados_validos = array_map('intval', explode(',',config('constantes.estados_validos_analisis')));            
            }            
        } else {
            $id_etapa = config('constantes.etapa_elaboracion');
            if (config('constantes.estados_validos_elaboracion') != "") {
                $estados_validos = array_map('intval', explode(',',config('constantes.estados_validos_elaboracion')));
            }
        }
        if (trim($nombre) != "") {
            // INICIALIZO VARIABLES
            $resultado        = "";
            $similitud        = [];
            $similitud[0]     = 0;
            $similitud[1]     = 0;
            $similitud[2]     = 0;
            $peso             = [];
            $conteo           = [];
            $peso_ponderado   = [];
            $conteo_ponderado = [];
            $longitud         = 0;


            // FILTRO LOS REGISTROS DE LA ETAPA, SEGUN EL TIPO DE GESTION Y LOS ESTADOS DE LOS REGISTROS VALIDOS
            $muestra = Resolucion::OrderBy('id_resolucion','ASC')
                ->where('id_etapa',$id_etapa)                
                ->where('id_tipo_gestion',$id_tipo_gestion)
            ;
            if (count($estados_validos) > 0) {
                $muestra->whereIn('id_estado_resolucion', $estados_validos);
            }
            $total_registros = $muestra->count();
            
            // SE EXTRAEN TODAS LAS PALABRAS DE LA CUERDA            
            $palabras_total = explode(" ",$nombre);
            $palabras = [];
            $total_palabras = 0;
            for ($i = 0; $i < count($palabras_total); ++$i) {
                $palabras_total[$i] = baseModel::quitarTildes($palabras_total[$i]);            
                if ((strlen($palabras_total[$i]) > config('constantes.tolerancia')) && !in_array($palabras_total[$i],$tipo_gestion)) {
                    $palabras[] = $palabras_total[$i];
                    $total_palabras++;
                }
            }
            // SE REALIZA LA VERIFICACIÓN PALABRA POR PALABRA
            foreach ($palabras as $palabra) { 
                
                // SI LA LOGINTUD DE LA PALABRA ES MAYOR QUE LA TOLERENCIA Y LA PALABRA NO ESTA CONTENIDO EN LA DEFINICION DEL TIPO DE GESTION
                if ((strlen($palabra) > config('constantes.tolerancia')) && (!in_array($palabra,$tipo_gestion))) {

                    // CONTADOR DE PALABRAS EN LA CUERDA
                    $longitud = $longitud + 1;

                    // FILTRA LOS NOMBRES DE LOS REGISTROS POR ETAPA, TIPO DE GESTION, ESTADO Y CUANDO EL NOMBRE CONTENGA LA PALABRA
                    $similitudes = Resolucion::OrderBy('id_resolucion','ASC')
                        ->where('id_etapa','=',$id_etapa)  
                        ->whereIn('id_tipo_gestion',$condicion)
                        ->where(DB::raw('public.quitar_tilde(nombre)'),'ilike',"%" . baseModel::quitarTildes($palabra) . "%")
                        ->select('id_resolucion','nombre')
                    ;
                    if (count($estados_validos) > 0) {
                        $similitudes->whereIn('id_estado_resolucion', $estados_validos);
                    }                    

                    $coleccion = $similitudes->count();
                    $similitudes = $similitudes->get();

                    // DETERMINO EL PESO DE LA PALABRA (SU LONGITUD)
                    $peso[]             = strlen($palabra);
                    // DETERMINO CUANTAS SIMILITUDES SE ENCONTRARON
                    $conteo[]           = $coleccion;   
                    // EL PESO PONDERADO ES LA LOGINTUD DE LA PALABRA / LA LONGITUD DE LA CUERDA         
                    $peso_ponderado[]   = strlen($nombre) > 0  ? strlen($palabra)/strlen($nombre)         : 0;
                    // EL CONTEO PONDERADO ES LA CANTIDAD DE SIMILITUDES / EL TOTAL DE LOS REGISTROS FILTRADOS
                    $conteo_ponderado[] = $total_registros > 0 ? $coleccion / $total_registros : 0;

                    // SI EXISTEN REGISTROS QUE CONTENGAN EN EL NOMBRE CONTIENE LA PALABRA QUE ESTOY BUSCANDO
                    if ($coleccion > 0) {

                        // RECORRO TODOS LOS REGISTROS ENCONTRADOS QUE TIENE LA PALABRA QUE ESTOY BUSCANDO
                        foreach ($similitudes as $index => $item) {

                            // SI EL NOMBRE DEL REGISTRO TIENE MAS PALABRAS QUE EL NOMBRE QUE EVALUO
                                $existencia = 0;
                                for ($x = 0; $x < count($palabras); $x++){
                                    if ((strlen($palabras[$x]) > config('constantes.tolerancia')) && (!in_array($palabras[$x],$tipo_gestion) && (str_contains($item->nombre, $palabras[$x])))) {
                                        $existencia++;
                                    }
                                }
                                if ($existencia >= $total_palabras) {
                                    // SI LA RESOLUCION NO HA SIDO INCLUIDA EN LA CUERDA DE RESULTADOS
                                    if((strpos($resultado,trim($item->id_resolucion)) == false) and (trim($item->id_resolucion) != '')) {

                                        if ($resultado != "") {
                                            $resultado = $resultado . ",";
                                        }
                                        $resultado = $resultado . trim($item->id_resolucion);

                                        $acierto   = round(self::nivel_acierto($item->nombre, $nombre),0);

                                        $calidad = ($acierto > 100 ? 0 : ($acierto == 100 ? 1 : 2 ));

                                        $similitud[$calidad] = $similitud[$calidad] + 1;
                                    }
                                }
                            //}
                        }
                    }
                }
            };

            $estadistica = [];

            $total = 0;
            for ($index = 0; $index < $longitud; $index++) {
                $estadistica[$palabras[$index]] = [
                    "peso"              => $peso[$index],
                    "peso_ponderado"    => $peso_ponderado[$index],
                    "conteo"            => $conteo[$index],
                    "conteo_ponderado"  => $conteo_ponderado[$index], 
                    "indice"            => $peso_ponderado[$index] * $conteo_ponderado[$index]];
                $total = $total + ($peso_ponderado[$index] * $conteo_ponderado[$index]);
            }
                    
            $total = count($palabras) > 0 ? round($total / count($palabras),5) * 100 : 0;

            $estadistica = [];
            $estadistica['indice_rechazo'] = $total;
            $estadistica['resultado']      = $resultado;
            $estadistica['similitud']      = str_pad($similitud[0], 6, "0", STR_PAD_LEFT) . "," . str_pad($similitud[1], 6, "0", STR_PAD_LEFT) . "," . str_pad($similitud[2], 6, "0", STR_PAD_LEFT);

        } else {
            $estadistica = [];
            $estadistica['indice_rechazo'] = 0;
            $estadistica['resultado']      = "";
            $estadistica['similitud']      = str_pad(0, 6, "0", STR_PAD_LEFT) . "," . str_pad(0, 6, "0", STR_PAD_LEFT) . "," . str_pad(0, 6, "0", STR_PAD_LEFT);
        }

        return $estadistica;
    }
    

    public function similitud_nombre($nombre,$llaves="") {
        
        $llaves    = array_map('intval', explode(',', $llaves));           

        $registros = Resolucion::orderby('id_resolucion','ASC')                                
            ->whereIn('id_resolucion', $llaves)  
            ->Where('esta_activo','=',true)
            ->get();
        ;   
        
        $resultado = [];
        $resultado[0] = 0;
        $resultado[1] = 0;
        $resultado[2] = 0;        

        foreach ($registros as $index => $item) {    
            $acierto = round(self::nivel_acierto($item->nombre, $nombre),0);        
            if ($acierto > 100) {
                $resultado[0] = $resultado[0] + 1;
            } else {
                if ($acierto == 100) {
                    $resultado[1] = $resultado[1] + 1;                
                } else {
                    $resultado[2] = $resultado[2] + 1;
                }
            }        
        }

        if ($resultado[1] > 0) {
            $response = "B";
        } else {
            if ($resultado[0] > 0) {
                $response = "A";
            } else {
                $response = "C";
            }
        }
        return json_encode($response);
    }

    public function numeroAletras($number) {
        $formatterES = new NumberFormatter("es", NumberFormatter::SPELLOUT);
        return($formatterES->format($number));
    }   

}
