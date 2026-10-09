<?php

namespace Services;

use Models\ProveedorModel;

class ProveedorService {
    private $model;

    public function __construct() {
        $this->model = new ProveedorModel();
    }

    public function obtenerProveedores(string $busqueda = '') {
        return $this->model->selectProveedores($busqueda);
    }

    public function obtenerProveedor(int $id) {
        $proveedor = $this->model->selectProveedor($id);
        if (!$proveedor) {
            throw new \Exception("Proveedor no encontrado.");
        }
        return $proveedor;
    }

    public function registrarProveedor(array $datos) {
        $datosSaneados = $this->validarDatos($datos);

        // Verificar duplicidad (CA-02 de HU01)
        $existe = $this->model->selectProveedorPorDocumento($datosSaneados['numero_documento']);
        if ($existe) {
            throw new \Exception("Ya existe un proveedor registrado con el documento " . $datosSaneados['numero_documento']);
        }

        $idGenerado = $this->model->insert($datosSaneados);
        if (!$idGenerado) {
            throw new \Exception("Error al registrar el proveedor en la base de datos.");
        }
        
        return $idGenerado;
    }

    public function actualizarProveedor(int $id, array $datos) {
        // Verificar que el proveedor exista
        $this->obtenerProveedor($id);

        $datosSaneados = $this->validarDatos($datos);

        // Verificar duplicidad ignorando el ID actual (CA-02 de HU02)
        $existe = $this->model->selectProveedorPorDocumento($datosSaneados['numero_documento'], $id);
        if ($existe) {
            throw new \Exception("Ya existe otro proveedor registrado con el documento " . $datosSaneados['numero_documento']);
        }

        $actualizado = $this->model->update($id, $datosSaneados);
        if ($actualizado === false) {
            throw new \Exception("Error al actualizar el proveedor.");
        }
        
        return true;
    }

    public function desactivarProveedor(int $id) {
        $this->obtenerProveedor($id);
        
        // CA-03 de HU02: Desactivado lógico
        $actualizado = $this->model->update($id, ['estado' => 0]);
        if ($actualizado === false) {
            throw new \Exception("Error al desactivar el proveedor.");
        }
        
        return true;
    }

    private function validarDatos(array $datos) {
        // Mapeo esperado desde el frontend
        // frontend envia: nombre, nro_documento, id_tipo_documento, telefono, estado
        // backend espera: razon_social, numero_documento, id_tipo_documento, telefono, estado
        
        $razonSocial = trim($datos['nombre'] ?? ($datos['razon_social'] ?? ''));
        $idTipoDocumento = (int)($datos['id_tipo_documento'] ?? 0);
        $numeroDocumento = trim($datos['nro_documento'] ?? ($datos['numero_documento'] ?? ''));
        $telefono = trim($datos['telefono'] ?? '');
        $estado = isset($datos['estado']) ? (int)$datos['estado'] : 1;

        if (empty($razonSocial) || empty($idTipoDocumento) || empty($numeroDocumento) || empty($telefono)) {
            throw new \Exception("Los campos Razón Social, Tipo Documento, Número Documento y Teléfono son obligatorios.");
        }

        // Formato estricto de DNI o RUC (CA-01)
        if ($idTipoDocumento === 1) { // Asumiendo 1 = DNI
            if (!preg_match('/^[0-9]{8}$/', $numeroDocumento)) {
                throw new \Exception("El DNI debe contener exactamente 8 dígitos.");
            }
        } elseif ($idTipoDocumento === 2) { // Asumiendo 2 = RUC
            if (!preg_match('/^[0-9]{11}$/', $numeroDocumento)) {
                throw new \Exception("El RUC debe contener exactamente 11 dígitos.");
            }
        } else {
            throw new \Exception("Tipo de documento no válido.");
        }

        // Validación de teléfono (solo números, permitiendo +, -, espacios, parentesis, entre 7 y 15 digitos)
        $digitosTelefono = preg_replace('/\D/', '', $telefono);
        if (strlen($digitosTelefono) < 7 || strlen($digitosTelefono) > 15) {
            throw new \Exception("El teléfono debe contener entre 7 y 15 dígitos.");
        }

        return [
            'razon_social' => $razonSocial,
            'id_tipo_documento' => $idTipoDocumento,
            'numero_documento' => $numeroDocumento,
            'telefono' => $telefono,
            'estado' => $estado
        ];
    }
}

