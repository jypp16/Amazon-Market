const Modal = {
    _active: false,

    alert(titulo, mensaje, tipo = 'info') {
        return new Promise((resolve) => {
            this._close();
            this._active = true;

            const iconMap = {
                success: 'fa-circle-check',
                error: 'fa-circle-xmark',
                warning: 'fa-triangle-exclamation',
                info: 'fa-circle-info'
            };

            const colorMap = {
                success: '#10b981',
                error: '#ef4444',
                warning: '#f59e0b',
                info: '#3b82f6'
            };

            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.setAttribute('data-modal-dynamic', 'alert');

            const container = document.createElement('div');
            container.className = 'modal-container modal-alert';

            const iconDiv = document.createElement('div');
            iconDiv.className = 'modal-icon';
            iconDiv.style.backgroundColor = colorMap[tipo] + '15';
            iconDiv.style.color = colorMap[tipo];
            const iconI = document.createElement('i');
            iconI.className = 'fa-solid ' + iconMap[tipo];
            iconDiv.appendChild(iconI);

            const h3 = document.createElement('h3');
            h3.className = 'modal-title';
            h3.textContent = titulo;

            const p = document.createElement('p');
            p.className = 'modal-message';
            p.textContent = mensaje;

            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'modal-actions';
            const btnOk = document.createElement('button');
            btnOk.className = 'modal-btn modal-btn-primary';
            btnOk.id = 'modal_ok_btn';
            const btnOkI = document.createElement('i');
            btnOkI.className = 'fa-solid fa-check';
            btnOk.appendChild(btnOkI);
            btnOk.appendChild(document.createTextNode(' Aceptar'));
            actionsDiv.appendChild(btnOk);

            container.appendChild(iconDiv);
            container.appendChild(h3);
            container.appendChild(p);
            container.appendChild(actionsDiv);
            overlay.appendChild(container);
            document.body.appendChild(overlay);

            setTimeout(() => overlay.classList.add('modal-active'), 10);

            btnOk.addEventListener('click', () => {
                this._close();
                resolve();
            });

            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    this._close();
                    resolve();
                }
            });
        });
    },

    confirm(titulo, mensaje, tipo = 'warning') {
        return new Promise((resolve) => {
            this._close();
            this._active = true;

            const iconMap = {
                warning: 'fa-triangle-exclamation',
                danger: 'fa-circle-exclamation',
                info: 'fa-circle-question'
            };

            const colorMap = {
                warning: '#f59e0b',
                danger: '#ef4444',
                info: '#3b82f6'
            };

            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.setAttribute('data-modal-dynamic', 'confirm');

            const container = document.createElement('div');
            container.className = 'modal-container modal-confirm';

            const iconDiv = document.createElement('div');
            iconDiv.className = 'modal-icon';
            iconDiv.style.backgroundColor = colorMap[tipo] + '15';
            iconDiv.style.color = colorMap[tipo];
            const iconI = document.createElement('i');
            iconI.className = 'fa-solid ' + iconMap[tipo];
            iconDiv.appendChild(iconI);

            const h3 = document.createElement('h3');
            h3.className = 'modal-title';
            h3.textContent = titulo;

            const p = document.createElement('p');
            p.className = 'modal-message';
            p.textContent = mensaje;

            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'modal-actions';

            const btnCancel = document.createElement('button');
            btnCancel.className = 'modal-btn modal-btn-secondary';
            btnCancel.id = 'modal_cancel_btn';
            const btnCancelI = document.createElement('i');
            btnCancelI.className = 'fa-solid fa-xmark';
            btnCancel.appendChild(btnCancelI);
            btnCancel.appendChild(document.createTextNode(' Cancelar'));
            actionsDiv.appendChild(btnCancel);

            const btnAccept = document.createElement('button');
            btnAccept.className = 'modal-btn modal-btn-danger';
            btnAccept.id = 'modal_accept_btn';
            const btnAcceptI = document.createElement('i');
            btnAcceptI.className = 'fa-solid fa-check';
            btnAccept.appendChild(btnAcceptI);
            btnAccept.appendChild(document.createTextNode(' Aceptar'));
            actionsDiv.appendChild(btnAccept);

            container.appendChild(iconDiv);
            container.appendChild(h3);
            container.appendChild(p);
            container.appendChild(actionsDiv);
            overlay.appendChild(container);
            document.body.appendChild(overlay);

            setTimeout(() => overlay.classList.add('modal-active'), 10);

            btnAccept.addEventListener('click', () => {
                this._close();
                resolve(true);
            });

            btnCancel.addEventListener('click', () => {
                this._close();
                resolve(false);
            });

            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    this._close();
                    resolve(false);
                }
            });
        });
    },

    success(titulo, mensaje) {
        return this.alert(titulo, mensaje, 'success');
    },

    error(titulo, mensaje) {
        return this.alert(titulo, mensaje, 'error');
    },

    warning(titulo, mensaje) {
        return this.alert(titulo, mensaje, 'warning');
    },

    _close() {
        const overlay = document.querySelector('.modal-overlay[data-modal-dynamic]');
        if (overlay) {
            overlay.classList.remove('modal-active');
            setTimeout(() => overlay.remove(), 300);
        }
        this._active = false;
    }
};

function showModalAlert(titulo, mensaje, tipo = 'info') {
    return Modal.alert(titulo, mensaje, tipo);
}

function showModalConfirm(titulo, mensaje, tipo = 'warning') {
    return Modal.confirm(titulo, mensaje, tipo);
}
