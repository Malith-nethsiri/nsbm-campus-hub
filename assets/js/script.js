(() => {
    "use strict";

    const registerForm = document.querySelector("[data-register-form]");
    if (!registerForm) {
        return;
    }

    const roleSelect = registerForm.querySelector("#role");
    const batchInput = registerForm.querySelector("#batch");
    const adminKeyGroup = registerForm.querySelector("#admin-key-group");
    const adminKeyInput = registerForm.querySelector("#admin_setup_key");

    const updateRoleFields = () => {
        const isStudent = roleSelect.value === "student";
        const isAdmin = roleSelect.value === "admin";

        batchInput.required = isStudent;
        adminKeyGroup.hidden = !isAdmin;
        adminKeyInput.required = isAdmin;

        if (!isAdmin) {
            adminKeyInput.value = "";
        }
    };

    roleSelect.addEventListener("change", updateRoleFields);
    updateRoleFields();

    document.querySelectorAll("form.needs-validation").forEach((form) => {
        form.addEventListener("submit", (event) => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }

            form.classList.add("was-validated");
        });
    });
})();
