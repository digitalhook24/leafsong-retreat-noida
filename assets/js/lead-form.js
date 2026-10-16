(function () {
  function digitsOnly(value) {
    return String(value || "").replace(/\D/g, "");
  }

  function getSelectedIsd(form) {
    const select = form.querySelector(".my_country_name");
    const option = select?.selectedOptions?.[0];
    const code = option?.dataset?.contry_code || option?.getAttribute("data-contry_code") || "91";
    return code ? `+${String(code).replace(/^\+/, "")}` : "+91";
  }

  function getEnquiryType(form) {
    const popup = document.getElementById("quotePopup");
    const popupTitle = document.getElementById("quoteTitle");
    if (popup && popup.contains(form) && popupTitle) {
      return popupTitle.textContent.trim();
    }

    const inlineTitle = form.querySelector(".quoteTitle");
    return inlineTitle ? inlineTitle.textContent.trim() : "Website enquiry";
  }

  function clearFormError(form) {
    form.querySelector(".form-error")?.remove();
  }

  function showFormError(form, message) {
    clearFormError(form);
    const errorEl = document.createElement("p");
    errorEl.className = "form-error";
    errorEl.setAttribute("role", "alert");
    errorEl.textContent = message;

    const submitBtn = form.querySelector(".quoteSubmit");
    if (submitBtn) {
      submitBtn.insertAdjacentElement("afterend", errorEl);
    } else {
      form.appendChild(errorEl);
    }
  }

  function setSubmitting(form, isSubmitting) {
    const submitBtn = form.querySelector(".quoteSubmit");
    if (!submitBtn) return;

    submitBtn.disabled = isSubmitting;
    if (isSubmitting) {
      submitBtn.dataset.originalLabel = submitBtn.textContent;
      submitBtn.textContent = "Submitting...";
    } else if (submitBtn.dataset.originalLabel) {
      submitBtn.textContent = submitBtn.dataset.originalLabel;
    }
  }

  function buildPayload(form) {
    const data = Object.fromEntries(new FormData(form).entries());
    data.phone = digitsOnly(data.phone);
    data.isd = getSelectedIsd(form);
    data.enquiryType = getEnquiryType(form);
    data.referralURL = window.location.href;

    if (!data.projectName) {
      data.projectName = "Leafsong Retreat";
    }

    return data;
  }

  async function submitLead(form) {
    const payload = buildPayload(form);
    const response = await fetch("submit.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify(payload),
    });

    const result = await response.json().catch(() => ({}));
    if (response.ok && result.ok !== false) {
      return true;
    }

    throw new Error(result.message || "Unable to submit lead");
  }

  function bindLeadForm(form) {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      clearFormError(form);

      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }

      setSubmitting(form, true);

      try {
        await submitLead(form);
        if (typeof closeQuotePopup === "function") {
          closeQuotePopup();
        }
        window.location.assign("thank-you.html");
      } catch (error) {
        showFormError(
          form,
          error instanceof Error
            ? error.message
            : "Unable to submit right now. Please call 8384072477."
        );
        setSubmitting(form, false);
      }
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".quoteForm").forEach(bindLeadForm);
  });
})();
