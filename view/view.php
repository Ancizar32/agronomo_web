<?php

/**
 *
 * @author Ancizar
 */
class view extends ViewBase
{

  function dibujar()
  {
?>
    <div id="container" class="container_login">
      <section class="login-block">
        <div class="container cont_login" style="border-radius: 10px;">
          <h2 class="text-center"><img src="resources/img/logo.png" width="150" height="150" class="d-inline-block align-top"
              alt=""></h2>
          <form class="login-form" action="JavaScript:validUser()">
            <div class="container">
              <div class="form-group col-sm-12">
                <label for="exampleInputEmail1" class="text-uppercase">USUARIO</label>
                <input type="text" class="form-control" name="user" id="user" placeholder="" data-noclear="false">
              </div>
              <div class="form-group col-sm-12">
                <label for="exampleInputPassword1" class="text-uppercase">CONTRASEÑA</label>
                <input id="password" type="password" name="code" class="form-control" placeholder="" data-noclear="false">
              </div>
              <div class="form-group d-flex justify-content-center flex-nowrap">
                <div class="form-group">
                  <button name="btnValidar" id="btnValidar" type="submit" class="btn btn-primary">INGRESAR</button>
                </div>
              </div>
              <div class="form-group d-flex justify-content-center flex-nowrap">
                <div class="form-group">
                  <div class="copy-text"><i class="fas fa-seedling"></i> De la industria al mercado, con calidad y precisión</div>
                </div>
              </div>
            </div>
          </form>

        </div>
      </section>
    </div>

    <div class="modal fade" tabindex="-1" aria-labelledby="exampleModalLabel">

      <div id="newTransferModal">
        <div class="form-row">
          <div class="form-group col-sm-12">
            <label for="psw_new" class="col-form-label">Nueva contraseña</label>
            <input type="password" class="form-control" id="psw_new" maxlength="50" autocomplete="false" data-noclear="false">
          </div>
          <div class="form-group col-sm-12">
            <label for="psw_rec" class="col-form-label">Confirmar contraseña</label>
            <input type="password" class="form-control" id="psw_rec" maxlength="50" autocomplete="false" data-noclear="false">
          </div>
        </div>
      </div>

    </div>

<?php
  }
}
?>