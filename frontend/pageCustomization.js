//value that tracks if dark mode is on or off
var isDarkMode = localStorage.getItem('mode');

//if it is true we call changeStyles automatically
if (isDarkMode === "true"){
  isDarkMode = "false";
  changeStyles();
}//end if isDarkMode

//function that checks if styles are applied anc changes them
function changeStyles(){
    //if darkMode is off, we turn dark mode on and adjust HTML text
    if (isDarkMode === "false"){
        $('body').removeClass();
        $('body').addClass('darkMode');
        $('#styleLabel').text("Light mode: Off");
        isDarkMode = "true";
    //if dark mode is on, we remove it and add light mode
    } else {
        $('body').removeClass();
        $('body').addClass('lightMode');
        $('#styleLabel').text("Light mode: On");
        isDarkMode = "false";
    }// end if-else
    //stores darkMode toggle in the browser's local storage
    localStorage.setItem('mode', isDarkMode);
}//end changeStyles()

//j-query selector that triggers function on switch toggle
$("#styleSwitch").change(changeStyles);

//console log for debugging
console.log(isDarkMode);
