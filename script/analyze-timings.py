#!/usr/bin/env python3

# This script reads a json file with timing information from OpenSearch, and
# checks that the timing information from all log lines "adds up", that is,
# that non-overlapping measurements covers more than 99% of the time spent in Total.


import argparse
import sys
import traceback
import json
import datetime

################################################################################
# GLOBAL STUFF
################################################################################

# Some global variables that is mostly to handle default values.

# The name of our script - used in output.
script_name = "analyze-timings"

# These are convenient to not have to pass to all functions, etc.
# Could have been wrapped in a class, though.
do_debug = False


################################################################################
# LOG AND OUTPUT STUFF
################################################################################

# I can't figure out how to make this a (static) method in Colors, that can be called by attributes init.
def build_color(num):
    return '\033[' + str(num) + 'm'


class Colors:
    # Control
    NORMAL = build_color(0)
    BOLD = build_color(1)
    UNDERLINE = build_color(4)
    # Colors
    GREEN = build_color(92)
    BLUE = build_color(34)
    YELLOW = build_color(93)
    RED = build_color(91)
    CYAN = build_color(96)
    MAGENTA = build_color(95)
    # Name is script name, rest is levels
    NAME = GREEN
    INFO = GREEN
    WARN = YELLOW
    DRYRUN = YELLOW
    ERROR = RED
    DEBUG = CYAN
    TRACE = MAGENTA
    UNKNOWN = RED
    STAGENAME = BLUE
    CHECKNAME = GREEN

    @staticmethod
    def remove_colors(string: str):
        """
        Remove any color codes from a string, making it suitable for output to file, instead of terminal.
        :param string: The string to remove color codes from.
        :return: The input string, with color codes removed.
        """
        return re.sub('\\033\\[\\d{1,2}m', '', string)


def output_log_msg(msg: str) -> None:
    print(msg, flush=True)


def format_log_msg(level: str, msg: str) -> str:
    """
    Format a string for log output. The level is colorized, if we are in an ssty.
    The datetime added, is localtime.
    :param level: The level (INFO, WARN, DEBUG, ...)
    :param msg: The msg to output_msg.
    :return: A formatted string.
    """
    output = Colors.NAME + "[" + script_name + "] " + Colors.NORMAL + datetime.datetime.now().strftime("%T.%f") + " "
    if level == "DEBUG":
        output += Colors.DEBUG
    elif level == "TRACE":
        output += Colors.TRACE
    elif level == "INFO":
        output += Colors.INFO
    elif level == "DRYRUN":
        output += Colors.DRYRUN
    elif level == "WARN":
        output += Colors.WARN
    elif level == "ERROR":
        output += Colors.ERROR
    elif level == "TODO":
        output += Colors.YELLOW
    else:
        output += Colors.UNKNOWN
    output += level + Colors.NORMAL + ": " + msg
    if sys.stdout.isatty():
        return output
    else:
        return Colors.remove_colors(output)


def info(msg: str) -> None:
    """
    Output a msg at LOG level.
    :param msg: The message to output.
    """
    output_log_msg(format_log_msg("INFO", msg))


def warn(msg: str) -> None:
    """
    Output a msg at WARN level.
    :param msg: The message to output.
    """
    output_log_msg(format_log_msg("WARN", msg))


def dryrun(msg: str) -> None:
    """
    Output a msg at DRYRUN level.
    :param msg: The message to output.
    """
    output_log_msg(format_log_msg("DRYRUN", msg))


def error(msg: str) -> None:
    """
    Output a msg at ERROR level.
    :param msg: The message to output.
    """
    output_log_msg(format_log_msg("ERROR", msg))


def trace(prefix="") -> None:
    """
    Output a trace at TRACE level, if the global variable "do_trace" is True
    :param: Optional parameter to set before the func name. This can be used by e.g. classes.
    """
    global do_trace
    if do_trace:
        top = traceback.extract_stack(None, 2)[0]
        func_name = top[2]
        output_log_msg(format_log_msg("TRACE", "Entering " + prefix + func_name))


def todo(msg: str) -> None:
    """
    Output a msg at TODO level, if the global variable "do_debug" is True
    :param msg: The message to output.
    """
    global do_debug
    if do_debug:
        output_log_msg(format_log_msg("TODO", msg))


def debug(msg: str) -> None:
    """
    Output a msg at DEBUG level, if the global variable "do_debug" is True
    :param msg: The message to output.
    """
    global do_debug
    if do_debug:
        output_log_msg(format_log_msg("DEBUG", msg))

################################################################################
# PARSE ARGS AND MAIN
################################################################################


def get_args() -> argparse.Namespace:
    """
    Configure the argument parsing system, and run it, to obtain the arguments given on the commandline.
    :return: The parsed arguments.
    """
    parser = argparse.ArgumentParser(formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("filename", help="The name of the file containing json data.")
    parser.add_argument("-d", "--debug", action="store_true",
                        help="Output extra debug information")
    parser.add_argument("-p", "--percentage", default=98,
                        type=float,
                        help="Check that the sums of non-overlapping measurements covers at least this percentage of Total.")
    parser.description = "Checks that the sums of non-overlapping measurements covers a percentage of Total"
    parser.epilog = """
Examples:
    Check a json measurement array using a file:
    """ + sys.argv[0] + """ measurement.json

"""
    args = parser.parse_args()
    return args




def main():
    start_time = datetime.datetime.now()
    try:
        global script_name
        args = get_args()

        global do_debug
        do_debug = args.debug

        debug("cli options: debug:" + str(args.debug))

        # Read the json file
        with open(args.filename) as json_file:
            data = json.load(json_file)
            for p in data:
                print (p['timestamp'])

    except Exception:
        output_log_msg(traceback.format_exc())
        stop_time = datetime.datetime.now()
        info("Time passed: " + str(stop_time - start_time))
        error("Verification " + Colors.RED + "FAILED" + Colors.NORMAL +
              " due to internal error (unhandled exception). Please file a bug report.")
        sys.exit(2)


main()
