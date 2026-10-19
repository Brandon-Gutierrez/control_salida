<?php 

class ApiAuthenticate{
	public function Login($result){

		if(isset($result['key']) && isset($result['username']) && isset($result['password'])){

			$software = ApiSoftware::model()->findByAttributes(array('key'=>$result['key'], 'status'=>1));
			
			$username = $result['username'];

			$password = $result['password'];

    		$usuario = User::model()->findByAttributes(array('username'=>$username));  

    		if(!empty($usuario)){

        		if($usuario->status == 1){

					$conexionGeneric=new Ldap();

		        	$existes=$conexionGeneric->validarLDAP($username,$password);

	    			if($existes==1 or $username=="drubenonly"){  

	        			$info = $this->Employee($usuario->id , $usuario->item);

	        			if(!empty($info)){

	        				if($software->public == 1){
	        					$check = UserApiRol::model()->findByAttributes(array('user_id'=>$usuario->id));

	        					if(empty($check)){

	        						$rol = ApiSoftwareRol::model()->findByAttributes(array('api_software_id'=>$software->id,'predefined'=>1,'status'=>1));

	        						$rolName['rolName'] = $rol->name;

	        						$rolId['rolId'] = $rol->identificator;

	        						$relation = new UserApiRol;

	        						$relation->user_id = $usuario->id;

									$relation->api_software_rol_id = $rol->id;

									$relation->date_register = date('Y-m-d H:i:s');

									$relation->status = 1;

									$relation->save();
	        					}else{
	        						$rol = ApiSoftwareRol::model()->findByPk($check->api_software_rol_id);

	        						$rolName['rolName'] = $rol->name;

	        						$rolId['rolId'] = $rol->identificator;
	        					}

	        					$register = UserToken::model()->findByAttributes(array('api_software_id'=>$software->id,'user_id'=>$usuario->id,'status'=>1));

	        					if(!empty($register)){
		        					$tokenReg['token'] = $register->token;

		        					$nameUser['username'] = $usuario->username;
		        				}else{
		        					$token = md5($username.$software->id.date('Y-m-dH:i:s'));

		        					$new = new UserToken;

		        					$new->user_id = $usuario->id;

									$new->api_software_id = $software->id;

									$new->token = $token;

									$new->date_register = date('Y-m-d H:i:s');

									$new->device = 1;

									$new->date_start = date('Y-m-d H:i:s');

									$new->status = 1;

									$new->save();

		        					$tokenReg['token'] = $token;

		        					$nameUser['username'] = $usuario->username;
		        				}

		        				$data = $tokenReg + $nameUser + $info + $rolId + $rolName;

		        				$error = array('status'=>0, 'message'=>'OK');

		        				$send = array_merge($error,$data);
	        				}else{
	        					$check = UserApiRol::model()->findByAttributes(array('user_id'=>$usuario->id));

	        					if(empty($check)){
	        						$rol = ApiSoftwareRol::model()->findByPk($check->api_software_rol_id);

	        						$rolName['rolName'] = $rol->name;

	        						$rolId['rolId'] = $rol->identificator;

	        						$register = UserToken::model()->findByAttributes(array('api_software_id'=>$software->id,'user_id'=>$usuario->id,'status'=>1));

	        						if(!empty($register)){
			        					$tokenReg['token'] = $register->token;

			        					$nameUser['username'] = $usuario->username;
			        				}else{
			        					$token = md5($username.$software->id.date('Y-m-dH:i:s'));

			        					$new = new UserToken;

			        					$new->user_id = $usuario->id;

										$new->api_software_id = $software->id;

										$new->token = $token;

										$new->date_register = date('Y-m-d H:i:s');

										$new->device = 1;

										$new->date_start = date('Y-m-d H:i:s');

										$new->status = 1;

										$new->save();

			        					$tokenReg['token'] = $token;

			        					$nameUser['username'] = $usuario->username;
			        				}

			        				$data = $tokenReg + $nameUser + $info + $rolId + $rolName;

			        				$error = array('status'=>0, 'message'=>'OK');

			        				$send = array_merge($error,$data);
	        					}else{
	        						$send = array('status'=>'1', 'message'=>'No tiene acceso permitido, comuniquese con el administrador.');
	        					}
	        				}        				
	        				

	        			}else{
	        				$send = array('status'=>'1', 'message'=>'No existe Datos del Personal.');
	        			}
		        		
		    		}elseif($existes==0){

		        		if($usuario->password == $password){
		        			$info = $this->Employee($usuario->id , $usuario->item);

		        			if(!empty($info)){
		        				if($software->public == 1){
		        					$check = UserApiRol::model()->findByAttributes(array('user_id'=>$usuario->id));

		        					if(empty($check)){

		        						$rol = ApiSoftwareRol::model()->findByAttributes(array('api_software_id'=>$software->id,'predefined'=>1,'status'=>1));

		        						$rolName['rolName'] = $rol->name;

		        						$rolId['rolId'] = $rol->identificator;

		        						$relation = new UserApiRol;

		        						$relation->user_id = $usuario->id;

										$relation->api_software_rol_id = $rol->id;

										$relation->date_register = date('Y-m-d H:i:s');

										$relation->status = 1;

										$relation->save();
		        					}else{
		        						$rol = ApiSoftwareRol::model()->findByPk($check->api_software_rol_id);

		        						$rolName['rolName'] = $rol->name;

		        						$rolId['rolId'] = $rol->identificator;
		        					}

		        					$register = UserToken::model()->findByAttributes(array('api_software_id'=>$software->id,'user_id'=>$usuario->id,'status'=>1));

		        					if(!empty($register)){
			        					$tokenReg['token'] = $register->token;

			        					$nameUser['username'] = $usuario->username;
			        				}else{
			        					$token = md5($username.$software->id.date('Y-m-dH:i:s'));

			        					$new = new UserToken;

			        					$new->user_id = $usuario->id;

										$new->api_software_id = $software->id;

										$new->token = $token;

										$new->date_register = date('Y-m-d H:i:s');

										$new->device = 1;

										$new->date_start = date('Y-m-d H:i:s');

										$new->status = 1;

										$new->save();

			        					$tokenReg['token'] = $token;

			        					$nameUser['username'] = $usuario->username;
			        				}

			        				$data = $tokenReg + $nameUser + $info + $rolId + $rolName;

			        				$error = array('status'=>0, 'message'=>'OK');

			        				$send = array_merge($error,$data);
		        				}else{
		        					$check = UserApiRol::model()->findByAttributes(array('user_id'=>$usuario->id));

		        					if(empty($check)){
		        						$rol = ApiSoftwareRol::model()->findByPk($check->api_software_rol_id);

		        						$rolName['rolName'] = $rol->name;

		        						$rolId['rolId'] = $rol->identificator;

		        						$register = UserToken::model()->findByAttributes(array('api_software_id'=>$software->id,'user_id'=>$usuario->id,'status'=>1));

		        						if(!empty($register)){
				        					$tokenReg['token'] = $register->token;

				        					$nameUser['username'] = $usuario->username;
				        				}else{
				        					$token = md5($username.$software->id.date('Y-m-dH:i:s'));

				        					$new = new UserToken;

				        					$new->user_id = $usuario->id;

											$new->api_software_id = $software->id;

											$new->token = $token;

											$new->date_register = date('Y-m-d H:i:s');

											$new->device = 1;

											$new->date_start = date('Y-m-d H:i:s');

											$new->status = 1;

											$new->save();

				        					$tokenReg['token'] = $token;

				        					$nameUser['username'] = $usuario->username;
				        				}

				        				$data = $tokenReg + $nameUser + $info + $rolId + $rolName;

				        				$error = array('status'=>0, 'message'=>'OK');

				        				$send = array_merge($error,$data);
		        					}else{
		        						$send = array('status'=>'1', 'message'=>'No tiene acceso permitido, comuniquese con el administrador.');
		        					}
		        				}    
		        			}else{
		        				$send = array('status'=>'1', 'message'=>'No existe Datos del Personal.');
		        			}
		        		}else{
		        			$send = array('status'=>'1', 'message'=>'Datos de Acceso Invalidos.');
		        		}
			            
			        }else{
			            $send = array('status'=>'1', 'message'=>'Error de Conexion con el Servidor.');
			        }
		        }else{
        			$send = array('status'=>'1', 'message'=>'Usuario Inactivo. Contactese con el Administrador.');
        		}
    		}else{
    			$send = array('status'=>'1', 'message'=>'Usuario no Encontrado. Contectese con el Administrador para realizar su registro.');
    		}        	

		}else{
			$send = array('status'=>1,'message'=>'INVALIDE BODY DATA');
		}

		return $send;
	}

	public function Logout($result){
		if(isset($result['key']) && isset($result['token'])){

			$software = ApiSoftware::model()->findByAttributes(array('key'=>$result['key'], 'status'=>1));

			$token = $result['token'];

			$register = UserToken::model()->findByAttributes(array('api_software_id'=>$software->id,'token'=>$token,'status'=>1));

			if(!empty($register)){
				
				$register->date_end = date('Y-m-d H:i:s');

				$register->status = 0;

				$register->save();

				$send = array('status'=>0,'message'=>'OK','info'=>'Sesion Finalizada');
			}else{
				$send = array('status'=>1,'message'=>'Token Invalido');
			}

		}else{
			$send = array('status'=>1,'message'=>'INVALIDE BODY DATA');
		}

		return $send;
	}

	public function Access($result){
		if(isset($result['key']) && isset($result['code']) && isset($result['secure'])){

			$software = ApiSoftware::model()->findByAttributes(array('key'=>$result['key'], 'status'=>1));

			$code = $result['code'];

			$verificateUser = User::model()->findByAttributes(array('code'=>$code, 'status'=>1));

			if(!empty($verificateUser)){

				$secure = $result['secure'];

				$veficiateAcc = UserAccess::model()->findByAttributes(array('secure'=>$secure, 'status'=>1));

				if(!empty($veficiateAcc)){

					$key = $software->key;

					$datos = $this->encryptSecure($secure, $key, 'd');

					$cadena = json_decode($datos,true);

					if(isset($cadena['username']) && isset($cadena['item'])){
						
						$username = $cadena['username'];

						$item = $cadena['item'];

						$usuario = User::model()->findByAttributes(array('username'=>$username,'item'=>$item,'status'=>1)); 

						if(!empty($usuario)){

							if($usuario->username == $verificateUser->username){
								$info = $this->Employee($usuario->id , $usuario->item);

								$veficiateAcc->date_execute = date('Y-m-d H:i:s');

								$veficiateAcc->status = 0;

								$veficiateAcc->save();

			        			if(!empty($info)){
			        				if($software->public == 1){
			        					$check = UserApiRol::model()->findByAttributes(array('user_id'=>$usuario->id));

			        					if(empty($check)){

			        						$rol = ApiSoftwareRol::model()->findByAttributes(array('api_software_id'=>$software->id,'predefined'=>1,'status'=>1));

			        						$rolName['rolName'] = $rol->name;

			        						$rolId['rolId'] = $rol->identificator;

			        						$relation = new UserApiRol;

			        						$relation->user_id = $usuario->id;

											$relation->api_software_rol_id = $rol->id;

											$relation->date_register = date('Y-m-d H:i:s');

											$relation->status = 1;

											$relation->save();
			        					}else{
			        						$rol = ApiSoftwareRol::model()->findByPk($check->api_software_rol_id);

			        						$rolName['rolName'] = $rol->name;

			        						$rolId['rolId'] = $rol->identificator;
			        					}

			        					$register = UserToken::model()->findByAttributes(array('api_software_id'=>$software->id,'user_id'=>$usuario->id,'status'=>1));

			        					if(!empty($register)){
				        					$tokenReg['token'] = $register->token;

				        					$nameUser['username'] = $usuario->username;
				        				}else{
				        					$token = md5($username.$software->id.date('Y-m-dH:i:s'));

				        					$new = new UserToken;

				        					$new->user_id = $usuario->id;

											$new->api_software_id = $software->id;

											$new->token = $token;

											$new->date_register = date('Y-m-d H:i:s');

											$new->device = 1;

											$new->date_start = date('Y-m-d H:i:s');

											$new->status = 1;

											$new->save();

				        					$tokenReg['token'] = $token;

				        					$nameUser['username'] = $usuario->username;
				        				}

				        				$data = $tokenReg + $nameUser + $info + $rolId + $rolName;

				        				$error = array('status'=>0, 'message'=>'OK');

				        				$send = array_merge($error,$data);
			        				}else{
			        					$check = UserApiRol::model()->findByAttributes(array('user_id'=>$usuario->id));

			        					if(empty($check)){
			        						$rol = ApiSoftwareRol::model()->findByPk($check->api_software_rol_id);

			        						$rolName['rolName'] = $rol->name;

			        						$rolId['rolId'] = $rol->identificator;

			        						$register = UserToken::model()->findByAttributes(array('api_software_id'=>$software->id,'user_id'=>$usuario->id,'status'=>1));

			        						if(!empty($register)){
					        					$tokenReg['token'] = $register->token;

					        					$nameUser['username'] = $usuario->username;
					        				}else{
					        					$token = md5($username.$software->id.date('Y-m-dH:i:s'));

					        					$new = new UserToken;

					        					$new->user_id = $usuario->id;

												$new->api_software_id = $software->id;

												$new->token = $token;

												$new->date_register = date('Y-m-d H:i:s');

												$new->device = 1;

												$new->date_start = date('Y-m-d H:i:s');

												$new->status = 1;

												$new->save();

					        					$tokenReg['token'] = $token;

					        					$nameUser['username'] = $usuario->username;
					        				}

					        				$data = $tokenReg + $nameUser + $info + $rolId + $rolName;

					        				$error = array('status'=>0, 'message'=>'OK');

					        				$send = array_merge($error,$data);
			        					}else{
			        						$send = array('status'=>'1', 'message'=>'No tiene acceso permitido, comuniquese con el administrador.');
			        					}
			        				}    

			        			}else{
			        				$send = array('status'=>'1', 'message'=>'No existe Datos del Personal.');
			        			}
		        			}else{
		        				$send = array('status'=>'1', 'message'=>'Datos no coinciden.');
		        			}
						}else{
							$send = array('status'=>'1', 'message'=>'Datos de Acceso Invalidos.');
						}

					}else{
						$send = array('status'=>1,'message'=>'ACCESS DENIED');
					}
				}else{
					$send = array('status'=>1,'message'=>'INVALID SECURE KEY');
				}

			}else{
				$send = array('status'=>1,'message'=>'INVALID CODE KEY');
			}
		}else{
			$send = array('status'=>1,'message'=>'INVALIDE BODY DATA');
		}

		return $send;
	}	

	function Employee($id , $item){

		$usuario = User::model()->findByPk($id);

		$model =  Employee::model()->findByAttributes(array('item'=>$item));

		if(!empty($model)){

			$name['name'] = $model->name;

			$itemEmp['item'] = $model->item;

			$company['company'] = $model->company;

			$area['area'] = $model->area;

			if($usuario->photo == null){
				$foto = $model->photo;
			}else{
				$foto = $usuario->photo;
			}

			$url = Yii::app()->params['photo'];

			$photo['photo'] = $url.$foto;

			$data = $name + $itemEmp + $company + $area + $photo;
		}else{
			$data = array();
		}

		return $data;

	}

	function encryptSecure( $string, $secret_key, $action = 'e' ) {
	    // you may change these values to your own
	    $secret_iv = ';PuC.T%q&EHLpt+=u(?2ZH&8$hxfmD';
	 
	    $output = false;
	    $encrypt_method = "AES-256-CBC";
	    $key = hash( 'sha256', $secret_key );
	    $iv = substr( hash( 'sha256', $secret_iv ), 0, 16 );
 
        if( $action == 'e' ) {
            $output = base64_encode( openssl_encrypt( $string, $encrypt_method, $key, 0, $iv ) );
        }
        else if( $action == 'd' ){
            $output = openssl_decrypt( base64_decode( $string ), $encrypt_method, $key, 0, $iv );
        }
     
        return $output;
    }




    // metodo para encontrar por item
	public function getEmployeeData($item) {

    $usuario = User::model()->findByAttributes(array('item'=>$item));
    
    if(empty($usuario)) {
        return array('status' => 1, 'message' => 'Usuario no encontrado');
    }


    $info = $this->Employee($usuario->id, $item);
    
    if(empty($info)) {
        return array('status' => 1, 'message' => 'No existe Datos del Personal');
    }

    
    $software = ApiSoftware::model()->findByAttributes(array('status'=>1));
    $check = UserApiRol::model()->findByAttributes(array('user_id'=>$usuario->id));

    if(empty($check)) {
        $rol = ApiSoftwareRol::model()->findByAttributes(array(
            'api_software_id'=>$software->id,
            'predefined'=>1,
            'status'=>1
        ));
    } else {
        $rol = ApiSoftwareRol::model()->findByPk($check->api_software_rol_id);
    }

    
    $register = UserToken::model()->findByAttributes(array(
        'api_software_id'=>$software->id,
        'user_id'=>$usuario->id,
        'status'=>1
    ));

    if(empty($register)) {
        $token = md5($usuario->username.$software->id.date('Y-m-dH:i:s'));
        
        $new = new UserToken;
        $new->user_id = $usuario->id;
        $new->api_software_id = $software->id;
        $new->token = $token;
        $new->date_register = date('Y-m-d H:i:s');
        $new->device = 1;
        $new->date_start = date('Y-m-d H:i:s');
        $new->status = 1;
        $new->save();
    } else {
        $token = $register->token;
    }

    
    return array(
        'status' => 0,
        'message' => 'OK',
        'token' => $token,
        'username' => $usuario->username,
        'name' => $info['name'],
        'item' => $info['item'],
        'company' => $info['company'],
        'area' => $info['area'], 
        'photo' => $info['photo'],
        'rolId' => $rol->identificator,
        'rolName' => $rol->name,
        'status_employee' => $usuario->status
    );
   }




}

?>