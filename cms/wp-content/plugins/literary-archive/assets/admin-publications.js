(function () {
	"use strict";

	document.addEventListener("DOMContentLoaded", function () {
		var rowsBody = document.getElementById("literary-archive-publications-rows");
		var template = document.getElementById("literary-archive-publication-row-template");
		var addButton = document.getElementById("literary-archive-add-publication");

		if (!rowsBody || !template || !addButton) {
			return;
		}

		addButton.addEventListener("click", function () {
			var nextIndex = rowsBody.querySelectorAll(".literary-archive-publication-row").length;
			var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
			var wrapper = document.createElement("tbody");
			wrapper.innerHTML = html;
			rowsBody.appendChild(wrapper.firstElementChild);
		});

		rowsBody.addEventListener("click", function (event) {
			if (event.target.classList.contains("literary-archive-remove-publication")) {
				event.target.closest(".literary-archive-publication-row").remove();
			}
		});
	});
})();
